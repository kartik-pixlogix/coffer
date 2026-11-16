export type NumberFormat = 'indian' | 'international';

export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    avatar_url: string | null;
    theme: 'light' | 'dark' | null;
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
