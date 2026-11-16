<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\VaultItemType;

/**
 * The provider catalog (spec §14.1, VaultAddCatalog.dc.html): the same list for every
 * workspace, kept in code (D67). Providers show as monogram tiles, never logos. Each
 * entry picks the item type, a default URL, the "Next you'll fill in…" line and any
 * custom fields the template adds (label, secret).
 */
final class ProviderCatalog
{
    /** @var list<string> the category chips, in the design's order */
    public const array CATEGORIES = [
        'Hosting and cloud', 'Code and deploys', 'Google and email', 'DNS and domains', 'Payments', 'Stores and CMS', 'Apps and tools',
    ];

    /** @var array<string, Provider>|null */
    private static ?array $providers = null;

    /** @return array<string, Provider> key → provider, in the catalog's order */
    public static function providers(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $list = [
            self::make('hostinger', 'Hostinger', 'HO', 'Hosting and cloud', 'hPanel login', VaultItemType::Login, 'https://hpanel.hostinger.com', 'URL, username, password and 2FA'),
            self::make('google-workspace', 'Google Workspace', 'GW', 'Google and email', 'Admin console', VaultItemType::Login, 'https://admin.google.com', 'admin email, password and 2FA'),
            self::make('gmail', 'Gmail', 'GM', 'Google and email', 'Mailbox login', VaultItemType::Email, 'https://mail.google.com', 'email, password or app password and IMAP/SMTP'),
            self::make('github', 'GitHub', 'GH', 'Code and deploys', 'Login and 2FA', VaultItemType::Login, 'https://github.com/login', 'username, password, 2FA and tokens', [['Personal access token', true]]),
            self::make('gitlab', 'GitLab', 'GL', 'Code and deploys', 'Login and 2FA', VaultItemType::Login, 'https://gitlab.com/users/sign_in', 'URL, username, password and the 2FA setup key'),
            self::make('bitbucket', 'Bitbucket', 'BB', 'Code and deploys', 'Login and app passwords', VaultItemType::Login, 'https://bitbucket.org/account/signin', 'username, password, 2FA and app password', [['App password', true]]),
            self::make('vercel', 'Vercel', 'VC', 'Code and deploys', 'Login or API token', VaultItemType::ApiKey, 'https://vercel.com/account/tokens', 'team, token and expiry date', [['Team', false]]),
            self::make('netlify', 'Netlify', 'NE', 'Code and deploys', 'Login or API token', VaultItemType::Login, 'https://app.netlify.com', 'email, password and site ID', [['Site ID', false]]),
            self::make('aws', 'AWS', 'AW', 'Hosting and cloud', 'Root or IAM user', VaultItemType::Login, 'https://console.aws.amazon.com', 'account ID, user, password, 2FA and access keys', [['Account ID', false], ['Access key ID', false], ['Secret access key', true]]),
            self::make('google-cloud', 'Google Cloud', 'GC', 'Hosting and cloud', 'Console login', VaultItemType::Login, 'https://console.cloud.google.com', 'account, password, 2FA and project ID', [['Project ID', false]]),
            self::make('azure', 'Azure', 'AZ', 'Hosting and cloud', 'Portal login', VaultItemType::Login, 'https://portal.azure.com', 'account, password, 2FA and tenant ID', [['Tenant ID', false]]),
            self::make('digitalocean', 'DigitalOcean', 'DO', 'Hosting and cloud', 'Login and droplet SSH', VaultItemType::Login, 'https://cloud.digitalocean.com/login', 'email, password, 2FA and SSH key'),
            self::make('contabo', 'Contabo', 'CO', 'Hosting and cloud', 'VPS SSH', VaultItemType::Server, '', 'host, port, user and the private key'),
            self::make('hetzner', 'Hetzner', 'HZ', 'Hosting and cloud', 'Server SSH', VaultItemType::Server, '', 'host, port, user and the private key'),
            self::make('cloudflare', 'Cloudflare', 'CF', 'DNS and domains', 'Login and API token', VaultItemType::Login, 'https://dash.cloudflare.com', 'email, password, 2FA and API token', [['API token', true]]),
            self::make('godaddy', 'GoDaddy', 'GD', 'DNS and domains', 'Registrar login', VaultItemType::Login, 'https://sso.godaddy.com', 'customer number, password and domain list', [['Customer number', false]]),
            self::make('namecheap', 'Namecheap', 'NC', 'DNS and domains', 'Registrar login', VaultItemType::Login, 'https://www.namecheap.com/myaccount/login', 'username, password and 2FA'),
            self::make('razorpay', 'Razorpay', 'RZ', 'Payments', 'Dashboard and API keys', VaultItemType::Payment, 'https://dashboard.razorpay.com', 'key ID, key secret and webhook secret'),
            self::make('stripe', 'Stripe', 'ST', 'Payments', 'Dashboard and API keys', VaultItemType::Payment, 'https://dashboard.stripe.com', 'publishable key, secret key and webhook secret'),
            self::make('paypal', 'PayPal', 'PP', 'Payments', 'Business account and API', VaultItemType::Payment, 'https://www.paypal.com/businessmanage', 'client ID, secret and webhook ID'),
            self::make('shopify', 'Shopify', 'SH', 'Stores and CMS', 'Store admin', VaultItemType::Login, 'https://admin.shopify.com', 'store URL, staff email and password'),
            self::make('wordpress', 'WordPress', 'WP', 'Stores and CMS', 'wp-admin login', VaultItemType::Login, '', 'site URL, username and password'),
            self::make('apple-developer', 'Apple Developer', 'AD', 'Apps and tools', 'Developer account', VaultItemType::Login, 'https://developer.apple.com/account', 'Apple ID, password, 2FA and team ID', [['Team ID', false]]),
            self::make('google-play', 'Google Play Console', 'GP', 'Apps and tools', 'Play Console login', VaultItemType::Login, 'https://play.google.com/console', 'Google account, password and 2FA'),
            self::make('figma', 'Figma', 'FI', 'Apps and tools', 'Team login', VaultItemType::Login, 'https://www.figma.com/login', 'email, password and 2FA'),
            self::make('notion', 'Notion', 'NO', 'Apps and tools', 'Workspace login', VaultItemType::Login, 'https://www.notion.so/login', 'email, password and 2FA'),
            self::make('slack', 'Slack', 'SL', 'Apps and tools', 'Workspace admin', VaultItemType::Login, 'https://slack.com/signin', 'workspace URL, email, password and 2FA'),
            self::make('jira', 'Jira', 'JI', 'Apps and tools', 'Atlassian login', VaultItemType::Login, 'https://id.atlassian.com/login', 'site URL, email, password and API token', [['API token', true]]),
            self::make('openai', 'OpenAI', 'OA', 'Apps and tools', 'API key', VaultItemType::ApiKey, 'https://platform.openai.com/api-keys', 'the key, its project and expiry date', [['Project', false]]),
            self::make('meta-business', 'Meta Business', 'MB', 'Apps and tools', 'Business Manager', VaultItemType::Login, 'https://business.facebook.com', 'email, password, 2FA and business ID', [['Business ID', false]]),
            self::make('linkedin', 'LinkedIn', 'LI', 'Apps and tools', 'Company page admin', VaultItemType::Login, 'https://www.linkedin.com/login', 'email, password and 2FA'),
            self::make('custom', 'Custom', '+', 'Custom', 'Any website or app', VaultItemType::Login, '', 'whichever fields you add'),
        ];

        $out = [];

        foreach ($list as $provider) {
            $out[$provider->key] = $provider;
        }

        return self::$providers = $out;
    }

    /** @param list<array{0: string, 1: bool}> $custom [label, secret] pairs */
    private static function make(string $key, string $name, string $monogram, string $category, string $hint, VaultItemType $type, string $url, string $fills, array $custom = []): Provider
    {
        return new Provider($key, $name, $monogram, $category, $hint, $type, $url, $fills, array_map(fn (array $c) => ['label' => $c[0], 'secret' => $c[1]], $custom));
    }

    /** @return list<array{key: string, name: string, monogram: string, category: string, hint: string, type: string, url: string, fills: string, customFields: list<array{label: string, secret: bool}>}> */
    public static function all(): array
    {
        return array_values(array_map(fn (Provider $p) => $p->toArray(), self::providers()));
    }

    /** @return array{key: string, name: string, monogram: string, category: string, hint: string, type: string, url: string, fills: string, customFields: list<array{label: string, secret: bool}>}|null */
    public static function find(?string $key): ?array
    {
        return $key === null ? null : (self::providers()[$key] ?? null)?->toArray();
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::providers()[$key]);
    }

    /** "GH" for a provider, else the name's initials ("Domain saarthi-jv.in" → "DS"). */
    public static function monogramFor(?string $provider, string $name): string
    {
        $known = $provider === null || $provider === 'custom' ? null : (self::providers()[$provider] ?? null);

        if ($known !== null) {
            return $known->monogram;
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = mb_strtoupper(implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));

        return $initials === '' ? '?' : $initials;
    }

    public static function categoryOf(?string $provider): ?string
    {
        return $provider === null ? null : (self::providers()[$provider] ?? null)?->category;
    }
}
