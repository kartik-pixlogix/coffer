<?php

declare(strict_types=1);

namespace App\Http\Requests\Vault;

use App\Enums\VaultEnvironment;
use App\Enums\VaultItemType;
use App\Models\VaultItem;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\ProviderCatalog;
use App\Services\Vault\Totp;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The add / edit item form (spec §14.4, VaultAddLogin.dc.html). Template fields are
 * checked against ItemTemplates: `fields` may only hold the type's non-secret keys and
 * `secrets` only its secret keys (plus notes), so nothing secret is stored in plain text.
 * On edit an absent or empty secret keeps the stored value; `clear` removes one.
 * Authorisation is the controller's (it needs the vault).
 */
final class VaultItemRequest extends FormRequest
{
    public const int MAX_SECRET = 20000;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = app(CurrentCompany::class)->require();
        $kb = (int) config('vault.attachment_max_kb');

        return [
            'vault_id' => ['required', 'integer', Rule::exists('vaults', 'id')->where('company_id', $company->id)],
            'type' => ['required', Rule::enum(VaultItemType::class)],
            'provider' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! ProviderCatalog::exists(is_string($value) ? $value : null)) {
                    $fail('Pick a provider from the catalog.');
                }
            }],
            'name' => ['required', 'string', 'max:160'],
            'environment' => ['nullable', Rule::enum(VaultEnvironment::class)],
            'tags' => ['array', 'max:12'],
            'tags.*' => ['string', 'max:32'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'restricted' => ['boolean'],
            'strength' => ['nullable', 'integer', 'between:0,4'],
            'fields' => ['array'],
            'fields.*' => ['nullable'],
            'secrets' => ['array'],
            'secrets.*' => ['nullable', 'string', 'max:'.self::MAX_SECRET],
            'clear' => ['array'],
            'clear.*' => ['string', 'max:64'],
            'custom_fields' => ['array', 'max:30'],
            'custom_fields.*.id' => ['nullable', 'string', 'regex:/^[a-z0-9]{1,16}$/'],
            'custom_fields.*.label' => ['required', 'string', 'max:60'],
            'custom_fields.*.secret' => ['boolean'],
            'custom_fields.*.value' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['array', 'max:10'],
            'attachments.*' => ['file', 'max:'.$kb],
            'remove_attachments' => ['array'],
            'remove_attachments.*' => ['integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the item a name.',
            'vault_id.required' => 'Pick a vault.',
            'vault_id.exists' => 'Pick one of your vaults.',
            'custom_fields.*.label.required' => 'Every custom field needs a label.',
            'attachments.*.max' => 'Attachments can be up to 5 MB each.',
            'expires_at.date_format' => 'Pick a date.',
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = $this->itemType();

            if ($type === null) {
                return;
            }

            $templates = ItemTemplates::byKey($type);

            foreach ((array) $this->input('fields', []) as $key => $value) {
                $field = $templates[$key] ?? null;

                if ($field === null || $field['secret']) {
                    $validator->errors()->add("fields.{$key}", 'This field isn’t part of the template.');

                    continue;
                }

                $this->checkField($validator, (string) $key, $field, $value);
            }

            $allowed = [...ItemTemplates::secretKeys($type), ItemTemplates::NOTES];

            foreach (array_keys((array) $this->input('secrets', [])) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add("secrets.{$key}", 'This field isn’t part of the template.');
                }
            }

            $totp = $this->input('secrets.totp');

            if (is_string($totp) && $totp !== '' && Totp::normalise($totp) === null) {
                $validator->errors()->add('secrets.totp', 'Setup keys use letters A–Z and digits 2–7.');
            }

            foreach ((array) $this->input('clear', []) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add('clear', 'Only template secrets can be cleared.');
                }
            }
        }];
    }

    public function itemType(): ?VaultItemType
    {
        $item = $this->route('item');
        $value = $this->input('type', $item instanceof VaultItem ? $item->type->value : null);

        return is_string($value) ? VaultItemType::tryFrom($value) : null;
    }

    /** @param array{key: string, label: string, kind: string, secret: bool, options?: list<string>} $field */
    private function checkField(Validator $validator, string $key, array $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $fail = fn (string $message) => $validator->errors()->add("fields.{$key}", $message);

        match ($field['kind']) {
            'toggle' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true) ? null : $fail('Turn it on or off.'),
            'number' => is_numeric($value) && (int) $value >= 0 && (int) $value <= 1_000_000 ? null : $fail("{$field['label']} must be a number."),
            'select' => in_array($value, $field['options'] ?? [], true) ? null : $fail("Pick a {$field['label']}."),
            // V6 (D116): only http(s) addresses; javascript:, data: and the like are refused with a reason.
            'url' => match (true) {
                ! is_string($value) || mb_strlen($value) > 2048 || preg_match('#^(https?://)?[^\s]+$#i', $value) !== 1 => $fail('Enter a web address.'),
                preg_match('#^[a-z][a-z0-9+.\-]*:(?!\d)#i', $value) === 1 && preg_match('#^https?://#i', $value) !== 1 => $fail('Use an http or https address.'),
                default => null,
            },
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : $fail('Enter an email address.'),
            default => is_string($value) && mb_strlen($value) <= 2048 ? null : $fail("{$field['label']} is too long."),
        };
    }
}
