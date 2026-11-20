export type NumberFormat = 'indian' | 'international';

/** The brand gradient (D147): a preset key from accent-presets.json, or 'custom'. */
export type Accent = {
    preset: string;
    stops: [string, string, string];
    angle: number;
    onColor: string;
};

export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    avatar_url: string | null;
    theme: 'light' | 'dark' | null;
    accent: Accent;
    timezone: string;
    number_format: NumberFormat;
    two_factor_enabled: boolean;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
