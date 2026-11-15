import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/**
 * Keep `element` visible inside its scrolling `container` without scrolling the page
 * (Element.scrollIntoView would also scroll every ancestor, including the window).
 */
export function scrollIntoViewWithin(
    container: HTMLElement | null,
    element: Element | null,
): void {
    if (!container || !(element instanceof HTMLElement)) {
        return;
    }

    const box = container.getBoundingClientRect();
    const item = element.getBoundingClientRect();

    if (item.top < box.top) {
        container.scrollTop -= box.top - item.top;
    } else if (item.bottom > box.bottom) {
        container.scrollTop += item.bottom - box.bottom;
    }
}
