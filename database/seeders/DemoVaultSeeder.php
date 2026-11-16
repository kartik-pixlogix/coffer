<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Vault\SaveVaultItem;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultAuditAction;
use App\Models\Company;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAuditEvent;
use App\Models\VaultFavourite;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Services\Vault\ItemAttachments;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * The Vault board's sample items (Vault.dc.html, VaultHealth.dc.html; spec §24) in Saarthi
 * Portal JV: Internal, Saarthi Portal JV, Asha Retail and Kiran Exports vaults, with
 * expiring, weak, reused, old and breached items for the health counts. Every secret is
 * an obvious demo value (listed in SECRETS, which the leak test checks never escape).
 * Demo accounts get 2FA with the published demo key (D71): `php artisan coffer:demo-2fa`
 * prints the current code.
 */
class DemoVaultSeeder extends Seeder
{
    /** Base32 2FA key of the demo accounts. Demo only: anyone can compute its codes. */
    public const string DEMO_2FA_KEY = 'JBSWY3DPEHPK3PXP';

    /** Every plaintext secret seeded here. */
    public const array SECRETS = [
        'demo-GH-Vx9#kq!4LmP2@zr8', 'DEMOGITHUBTOTPKEY234', 'demo-AWS-R00t#Brk-Gl4ss!91', 'DEMOAWSROOTTOTPKEY23',
        'demo-AWS-secret-key-wJalrXUtnFEMI', 'demo-GW-Gw!7mQ2#vL9xPz4', 'DEMOGOOGLEWSTOTPKEY2', 'demo-CF-Cf#82!dNs-Vx7q',
        'DEMOCLOUDFLARETOTP23', 'demo_rzp_secret_9Qx7Qx2', 'demo_whsec_saarthi_4471', 'demo-HO-Hp!4sha#Rt-2026',
        'demo_vcp_token_3f9a', 'saarthi2024', 'demo-contabo-passphrase-77', 'DEMO-PRIVATE-KEY-NOT-REAL',
        'abcd efgh ijkl mnop', 'Kiran@2025', 'demo-github-recovery-1a2b3c', 'demo-mysql-conn-saarthi',
        'Break-glass only. Daily work goes through IAM users with their own MFA.',
    ];

    public function __construct(
        private readonly SaveVaultItem $save,
        private readonly ItemAttachments $attachments,
        private readonly CurrentCompany $current,
    ) {}

    public function run(): void
    {
        $company = Company::query()->where('name', 'Saarthi Portal JV')->firstOrFail();
        $users = User::query()->whereIn('email', ['aarav@vertexlabs.in', 'ishita@vertexlabs.in', 'kabir@bluefininfotech.in', 'meera@bluefininfotech.in', 'rohan@vertexlabs.in'])
            ->get()->keyBy('email');
        [$aarav, $ishita, $kabir, $meera, $rohan] = [
            $users['aarav@vertexlabs.in'], $users['ishita@vertexlabs.in'], $users['kabir@bluefininfotech.in'], $users['meera@bluefininfotech.in'], $users['rohan@vertexlabs.in'],
        ];

        // The vault needs 2FA (spec §14.3). Meera is left without it to show the set-up prompt.
        foreach ([$aarav, $ishita, $kabir, $rohan] as $user) {
            $user->forceFill([
                'two_factor_secret' => encrypt(self::DEMO_2FA_KEY),
                'two_factor_recovery_codes' => encrypt((string) json_encode(['demo-recovery-0001', 'demo-recovery-0002'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        $this->current->run($company, function () use ($company, $aarav, $ishita, $kabir, $meera, $rohan): void {
            $vaults = [];

            foreach (['Internal', 'Saarthi Portal JV', 'Asha Retail', 'Kiran Exports'] as $position => $name) {
                $vaults[$name] = Vault::query()->updateOrCreate(['name' => $name], ['position' => $position, 'created_by' => $aarav->id]);
            }

            $grant = fn (User $user, Vault $vault, VaultAccessLevel $level) => VaultGrant::query()->create([
                'vault_id' => $vault->id, 'user_id' => $user->id, 'level' => $level, 'granted_by' => $aarav->id,
            ]);
            $grant($ishita, $vaults['Internal'], VaultAccessLevel::Edit);
            $grant($rohan, $vaults['Internal'], VaultAccessLevel::Use);
            $grant($ishita, $vaults['Saarthi Portal JV'], VaultAccessLevel::Edit);
            $grant($kabir, $vaults['Saarthi Portal JV'], VaultAccessLevel::Manage);
            $grant($meera, $vaults['Saarthi Portal JV'], VaultAccessLevel::Use);
            $grant($rohan, $vaults['Saarthi Portal JV'], VaultAccessLevel::Use);
            $grant($ishita, $vaults['Asha Retail'], VaultAccessLevel::Edit);
            $grant($ishita, $vaults['Kiran Exports'], VaultAccessLevel::Edit);

            $items = [];

            foreach ($this->items() as $key => $spec) {
                $items[$key] = $this->item($aarav, $vaults[$spec['vault']], $spec);
            }

            // The board's GitHub item is shared one level down with Rohan's vault access; Gmail goes to Rohan as View.
            VaultGrant::query()->create(['vault_item_id' => $items['gmail']->id, 'user_id' => $rohan->id, 'level' => VaultAccessLevel::View, 'granted_by' => $aarav->id]);

            foreach (['github', 'aws', 'gworkspace'] as $key) {
                VaultFavourite::query()->create(['vault_item_id' => $items[$key]->id, 'user_id' => $aarav->id, 'created_at' => now()]);
            }

            $this->contaboKey($items['contabo'], $aarav);
            $this->activity($company, $items['github'], $aarav, $ishita, $rohan);
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function items(): array
    {
        return [
            'github' => ['vault' => 'Internal', 'type' => 'login', 'provider' => 'github', 'name' => 'GitHub — vertexlabs org', 'rotated' => '2026-09-28',
                'fields' => ['url' => 'https://github.com/login', 'username' => 'vertexlabs-admin'],
                'secrets' => ['password' => 'demo-GH-Vx9#kq!4LmP2@zr8', 'totp' => 'DEMOGITHUBTOTPKEY234', 'recovery_codes' => 'demo-github-recovery-1a2b3c', 'notes' => 'Org owner account for CI and billing. Rotate it whenever someone with access leaves the team.'],
                'strength' => 4, 'tags' => ['ci', 'billing']],
            'aws' => ['vault' => 'Internal', 'type' => 'login', 'provider' => 'aws', 'name' => 'AWS — root account', 'rotated' => '2026-07-02', 'restricted' => true,
                'fields' => ['url' => 'https://console.aws.amazon.com', 'username' => 'aws-root@vertexlabs.in'],
                'secrets' => ['password' => 'demo-AWS-R00t#Brk-Gl4ss!91', 'totp' => 'DEMOAWSROOTTOTPKEY23', 'notes' => 'Break-glass only. Daily work goes through IAM users with their own MFA.'],
                'custom' => [['label' => 'Account ID', 'secret' => false, 'value' => '000011112222'], ['label' => 'Secret access key', 'secret' => true, 'value' => 'demo-AWS-secret-key-wJalrXUtnFEMI']],
                'strength' => 4],
            'gworkspace' => ['vault' => 'Internal', 'type' => 'login', 'provider' => 'google-workspace', 'name' => 'Google Workspace — super admin', 'rotated' => '2026-10-05',
                'fields' => ['url' => 'https://admin.google.com', 'username' => 'admin@vertexlabs.in'],
                'secrets' => ['password' => 'demo-GW-Gw!7mQ2#vL9xPz4', 'totp' => 'DEMOGOOGLEWSTOTPKEY2', 'notes' => 'Super admin for vertexlabs.in. Recovery codes are stored as a secure note in this vault.'],
                'strength' => 4],
            'cloudflare' => ['vault' => 'Internal', 'type' => 'login', 'provider' => 'cloudflare', 'name' => 'Cloudflare — DNS', 'rotated' => '2026-09-12',
                'fields' => ['url' => 'https://dash.cloudflare.com', 'username' => 'dns@vertexlabs.in'],
                'secrets' => ['password' => 'demo-CF-Cf#82!dNs-Vx7q', 'totp' => 'DEMOCLOUDFLARETOTP23', 'notes' => 'DNS for vertexlabs.in and all client domains we manage.'],
                'strength' => 3],
            'razorpay' => ['vault' => 'Saarthi Portal JV', 'type' => 'payment', 'provider' => 'razorpay', 'name' => 'Razorpay — live keys', 'rotated' => '2026-10-01',
                'fields' => ['mode' => 'Live', 'publishable_key' => 'rzp_live_DEMO7Qx2', 'url' => 'https://dashboard.razorpay.com'],
                'secrets' => ['secret_key' => 'demo_rzp_secret_9Qx7Qx2', 'webhook_secret' => 'demo_whsec_saarthi_4471', 'notes' => 'Live mode keys for the Saarthi portal.']],
            'hostinger' => ['vault' => 'Asha Retail', 'type' => 'login', 'provider' => 'hostinger', 'name' => 'Hostinger hPanel — Asha Retail', 'rotated' => '2026-08-18',
                'fields' => ['url' => 'https://hpanel.hostinger.com', 'username' => 'admin@asharetail.in'],
                'secrets' => ['password' => 'demo-HO-Hp!4sha#Rt-2026', 'notes' => 'Client hosting for asharetail.in. Renewal is billed to Asha Retail.'],
                'strength' => 3],
            'vercel' => ['vault' => 'Internal', 'type' => 'api_key', 'provider' => 'vercel', 'name' => 'Vercel — team API token', 'rotated' => '2026-08-24', 'expires' => '2026-11-24',
                'fields' => ['scopes' => 'team_vertexlabs · deploy', 'url' => 'https://vercel.com/account/tokens'],
                'secrets' => ['key' => 'demo_vcp_token_3f9a', 'notes' => 'Deploy token used by CI. Create the new one before revoking this.'],
                'custom' => [['label' => 'Team', 'secret' => false, 'value' => 'team_vertexlabs']]],
            'domain' => ['vault' => 'Saarthi Portal JV', 'type' => 'domain', 'provider' => null, 'name' => 'Domain saarthi-jv.in', 'rotated' => '2025-12-14', 'expires' => '2026-12-14',
                'fields' => ['domain' => 'saarthi-jv.in', 'registrar' => 'Hostinger', 'url' => 'https://hpanel.hostinger.com', 'auto_renew' => false, 'nameservers' => "ns1.dns-parking.com\nns2.dns-parking.com"],
                'secrets' => ['notes' => 'Auto-renew is off; confirm with Kabir before renewing.']],
            'mysql' => ['vault' => 'Saarthi Portal JV', 'type' => 'database', 'provider' => null, 'name' => 'MySQL — production database', 'rotated' => '2026-04-09',
                'fields' => ['engine' => 'MySQL', 'host' => '10.0.4.12', 'port' => 3306, 'database' => 'saarthi', 'user' => 'saarthi_app'],
                'secrets' => ['password' => 'saarthi2024', 'connection_string' => 'demo-mysql-conn-saarthi', 'notes' => 'App user for the portal database. Read replica details are in custom fields.'],
                'custom' => [['label' => 'Read replica host', 'secret' => false, 'value' => '10.0.4.13']],
                'strength' => 1],
            'contabo' => ['vault' => 'Saarthi Portal JV', 'type' => 'server', 'provider' => 'contabo', 'name' => 'Contabo VPS — SSH', 'rotated' => '2026-03-11',
                'fields' => ['host' => '203.0.113.24', 'port' => 22, 'user' => 'root'],
                'secrets' => ['private_key' => 'DEMO-PRIVATE-KEY-NOT-REAL', 'passphrase' => 'demo-contabo-passphrase-77', 'notes' => 'ed25519 private key attached. The passphrase is on this item.']],
            'gmail' => ['vault' => 'Internal', 'type' => 'email', 'provider' => 'gmail', 'name' => 'Gmail — support inbox', 'rotated' => '2026-02-20', 'breached' => true,
                'fields' => ['username' => 'support@vertexlabs.in', 'url' => 'https://mail.google.com', 'imap' => 'imap.gmail.com:993 SSL', 'smtp' => 'smtp.gmail.com:587 STARTTLS'],
                'secrets' => ['password' => 'abcd efgh ijkl mnop', 'notes' => 'App password for the helpdesk tool. Rotate and revoke the old one.'],
                'strength' => 1],
            'netlify' => ['vault' => 'Kiran Exports', 'type' => 'login', 'provider' => 'netlify', 'name' => 'Netlify — marketing site', 'rotated' => '2026-01-14', 'environment' => 'staging',
                'fields' => ['url' => 'https://app.netlify.com', 'username' => 'web@vertexlabs.in'],
                'secrets' => ['password' => 'Kiran@2025', 'notes' => 'Staging site for Kiran Exports marketing pages.'],
                'strength' => 1],
            'wpkiran' => ['vault' => 'Kiran Exports', 'type' => 'login', 'provider' => 'wordpress', 'name' => 'WordPress — Kiran Exports', 'rotated' => '2026-06-02',
                'fields' => ['url' => 'https://kiranexports.in/wp-admin', 'username' => 'kiran-admin'],
                'secrets' => ['password' => 'Kiran@2025'],
                'strength' => 1],
        ];
    }

    /** @param array<string, mixed> $spec */
    private function item(User $by, Vault $vault, array $spec): VaultItem
    {
        $item = $this->save->handle($by, [
            'vault_id' => $vault->id,
            'type' => $spec['type'],
            'provider' => $spec['provider'],
            'name' => $spec['name'],
            'environment' => $spec['environment'] ?? 'production',
            'fields' => $spec['fields'],
            'secrets' => $spec['secrets'],
            'custom_fields' => $spec['custom'] ?? [],
            'tags' => $spec['tags'] ?? [],
            'expires_at' => $spec['expires'] ?? null,
            'restricted' => $spec['restricted'] ?? false,
            'strength' => $spec['strength'] ?? null,
        ], null, [], true);

        $rotated = CarbonImmutable::parse((string) $spec['rotated'].' 11:30:00');
        $item->forceFill(['rotated_at' => $rotated, 'created_at' => $rotated, 'breached' => (bool) ($spec['breached'] ?? false), 'breach_checked_at' => isset($spec['breached']) ? now() : null])->save();

        return $item;
    }

    private function contaboKey(VaultItem $item, User $by): void
    {
        $path = tempnam(sys_get_temp_dir(), 'demo-key');
        file_put_contents((string) $path, "-----BEGIN OPENSSH PRIVATE KEY-----\nDEMO-PRIVATE-KEY-NOT-REAL\n-----END OPENSSH PRIVATE KEY-----\n");
        $this->attachments->store($item, new UploadedFile((string) $path, 'contabo-ed25519.key', 'application/octet-stream', null, true), $by);
        @unlink((string) $path);
    }

    /** The GitHub drawer's timeline (Vault.dc.html). */
    private function activity(Company $company, VaultItem $item, User $aarav, User $ishita, User $rohan): void
    {
        foreach ([
            [$ishita, VaultAuditAction::Rotated, 'password', '2026-09-28 11:31', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0', '203.0.113.40'],
            [$aarav, VaultAuditAction::Revealed, 'password', '2026-11-12 10:05', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', '198.51.100.12'],
            [$rohan, VaultAuditAction::Copied, 'password', '2026-11-14 18:42', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36', '203.0.113.77'],
        ] as [$user, $action, $field, $at, $agent, $ip]) {
            VaultAuditEvent::query()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'vault_id' => $item->vault_id,
                'vault_item_id' => $item->id,
                'item_name' => $item->name,
                'action' => $action,
                'field' => $field,
                'ip' => $ip,
                'user_agent' => $agent,
                'meta' => ['label' => 'Password'],
                'created_at' => CarbonImmutable::parse($at),
            ]);
        }
    }
}
