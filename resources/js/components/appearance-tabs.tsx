import { Moon, Sun } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Theme } from '@/hooks/use-theme';
import { useTheme } from '@/hooks/use-theme';
import { cn } from '@/lib/utils';

const options: { value: Theme; icon: LucideIcon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
];

/** Light / Dark choice as a design-system segmented control (cf-seg). */
export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { theme, setTheme } = useTheme();

    return (
        <div
            className={cn('cf-seg', className)}
            role="group"
            aria-label="Theme"
            {...props}
        >
            {options.map(({ value, icon: Icon, label }) => (
                <button
                    key={value}
                    type="button"
                    className="cf-seg-btn"
                    aria-pressed={theme === value}
                    onClick={() => setTheme(value)}
                >
                    <Icon size={16} strokeWidth={1.5} aria-hidden="true" />
                    {label}
                </button>
            ))}
        </div>
    );
}
