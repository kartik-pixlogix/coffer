import type { ReactNode } from 'react';

export type GalleryGroup =
    | 'Brand'
    | 'Actions and navigation'
    | 'Forms'
    | 'Feedback and overlays'
    | 'Data and charts';

export type GalleryTheme = 'light' | 'dark';

/**
 * One gallery card, mirroring a design-system `preview.html`. Default-export one of
 * these from `components/gallery/sections/<component>.tsx`; the gallery finds it.
 */
export type GallerySection = {
    /** Matches the design-system folder name, e.g. "Button". */
    name: string;
    group: GalleryGroup;
    /** The preview's `@dsCard subtitle`. */
    subtitle?: string;
    /** 'split' = light and dark side by side (default); 'stack' = one above the other. */
    layout?: 'split' | 'stack';
    /** Order within the group (lower first). */
    order?: number;
    /** Rendered once per theme pane. Use `theme` to keep ids unique if needed. */
    render: (theme: GalleryTheme) => ReactNode;
};
