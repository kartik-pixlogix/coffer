import { Head } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { Fragment } from 'react';
import type { GalleryGroup, GallerySection } from '@/components/gallery/types';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { IconButton } from '@/components/ui/icon-button';
import { useTheme } from '@/hooks/use-theme';

const modules = import.meta.glob<{ default: GallerySection }>(
    '../../components/gallery/sections/*.tsx',
    { eager: true },
);

const GROUPS: GalleryGroup[] = [
    'Brand',
    'Feedback and overlays',
    'Forms',
    'Actions and navigation',
    'Data and charts',
];

const sections = Object.values(modules)
    .map((module) => module.default)
    .sort(
        (a, b) =>
            GROUPS.indexOf(a.group) - GROUPS.indexOf(b.group) ||
            (a.order ?? 100) - (b.order ?? 100) ||
            a.name.localeCompare(b.name),
    );

const slug = (name: string) => name.toLowerCase().replace(/[^a-z0-9]+/g, '-');

/**
 * /dev/ui — every UI-kit component in every state, light and dark side by side,
 * laid out like design/design-system/components/<Name>/preview.html. Local only.
 */
export default function UiGallery() {
    const { theme, setTheme } = useTheme();

    return (
        <>
            <Head title="UI kit" />
            <div className="cf-page">
                <div className="mx-auto flex max-w-[1400px] flex-col gap-5">
                    <header className="cf-pagehead">
                        <div>
                            <Breadcrumb
                                items={[
                                    { label: 'Coffer' },
                                    { label: 'UI kit' },
                                ]}
                            />
                            <h1 className="cf-h1">
                                <span className="t-display-light">
                                    Design system
                                </span>
                                <span className="t-display">UI kit</span>
                            </h1>
                        </div>
                        <div className="cf-actions">
                            <span className="t-caption t-muted">
                                {sections.length} components
                            </span>
                            <IconButton
                                icon={theme === 'dark' ? Sun : Moon}
                                size="lg"
                                aria-label={
                                    theme === 'dark'
                                        ? 'Switch to light theme'
                                        : 'Switch to dark theme'
                                }
                                onClick={() =>
                                    setTheme(
                                        theme === 'dark' ? 'light' : 'dark',
                                        { persist: false },
                                    )
                                }
                            />
                        </div>
                    </header>

                    <nav aria-label="Components" className="cf-card">
                        {GROUPS.map((group) => {
                            const items = sections.filter(
                                (s) => s.group === group,
                            );

                            if (items.length === 0) {
                                return null;
                            }

                            return (
                                <div key={group} className="mb-3 last:mb-0">
                                    <div className="cf-menu-label px-0">
                                        {group}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {items.map((s) => (
                                            <a
                                                key={s.name}
                                                href={`#${slug(s.name)}`}
                                                className="cf-chip"
                                            >
                                                {s.name}
                                            </a>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </nav>

                    {GROUPS.map((group) => (
                        <Fragment key={group}>
                            {sections
                                .filter((s) => s.group === group)
                                .map((section) => (
                                    <section
                                        key={section.name}
                                        id={slug(section.name)}
                                        className="cf-card cf-card--white flex scroll-mt-5 flex-col gap-3"
                                        aria-labelledby={`${slug(section.name)}-title`}
                                    >
                                        <div>
                                            <span className="t-micro t-muted">
                                                {group}
                                            </span>
                                            <h2
                                                id={`${slug(section.name)}-title`}
                                                className="cf-dot-title t-heading"
                                            >
                                                {section.name}
                                            </h2>
                                            {section.subtitle && (
                                                <p className="t-body t-muted m-0 mt-1">
                                                    {section.subtitle}
                                                </p>
                                            )}
                                        </div>
                                        <div
                                            className={
                                                section.layout === 'stack'
                                                    ? 'pv-wrap pv-wrap--stack'
                                                    : 'pv-wrap'
                                            }
                                        >
                                            {(['light', 'dark'] as const).map(
                                                (paneTheme) => (
                                                    <div
                                                        key={paneTheme}
                                                        className="cf pv overflow-x-auto"
                                                        data-theme={paneTheme}
                                                        role="group"
                                                        aria-label={`${section.name}, ${paneTheme} theme`}
                                                    >
                                                        <div className="pv-head">
                                                            {paneTheme ===
                                                            'light'
                                                                ? 'Light'
                                                                : 'Dark'}
                                                        </div>
                                                        {section.render(
                                                            paneTheme,
                                                        )}
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </section>
                                ))}
                        </Fragment>
                    ))}
                </div>
            </div>
        </>
    );
}
