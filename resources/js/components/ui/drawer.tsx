import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { useEffect, useId, useRef, useSyncExternalStore } from 'react';
import type { CSSProperties, KeyboardEvent, ReactNode } from 'react';
import { IconButton } from '@/components/ui/icon-button';
import { SCRIM_CLASS } from '@/components/ui/modal';
import { cn } from '@/lib/utils';

type DrawerLookProps = {
    /** Identity line in the graphite header: "Vertex Labs → Bluefin Infotech". */
    title: ReactNode;
    /** Caption under the title, in `on-graphite-muted`. */
    subtitle?: ReactNode;
    /** Avatar or monogram tile before the title. */
    media?: ReactNode;
    /** Second header row: the key figure, status pill. */
    figure?: ReactNode;
    /** The `surface-card` body. */
    children: ReactNode;
    closeLabel?: string;
    className?: string;
    style?: CSSProperties;
};

function DrawerHead({
    titleNode,
    subtitle,
    media,
    figure,
    close,
}: Pick<DrawerLookProps, 'subtitle' | 'media' | 'figure'> & {
    /** The title line (a Dialog.Title in live drawers). */
    titleNode: ReactNode;
    close: ReactNode;
}) {
    return (
        <div className="cf-drawer-head">
            <div
                className="cf-row"
                style={{ alignItems: 'flex-start', gap: 12 }}
            >
                {media}
                <span
                    style={{
                        flex: 1,
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 2,
                        minWidth: 0,
                    }}
                >
                    {titleNode}
                    {subtitle && (
                        <span
                            className="t-caption"
                            style={{ color: 'var(--on-graphite-muted)' }}
                        >
                            {subtitle}
                        </span>
                    )}
                </span>
                {close}
            </div>
            {figure && <div className="cf-row">{figure}</div>}
        </div>
    );
}

/** Display face for the header line (the preview's inline style). */
const TITLE_STYLE: CSSProperties = {
    fontFamily: 'var(--font-display)',
    fontSize: 18,
    lineHeight: '22px',
    fontWeight: 500,
};

function CloseButton({
    label,
    onClick,
}: {
    label: string;
    onClick?: () => void;
}) {
    return (
        <IconButton
            icon={X}
            variant="glass"
            iconSize={16}
            aria-label={label}
            onClick={onClick}
        />
    );
}

export type DrawerPanelProps = DrawerLookProps & {
    /** Accessible name of the region, e.g. "Settlement details". */
    'aria-label': string;
    onClose?: () => void;
    /** Move focus into the panel when it mounts (inline drawers opened from a row). */
    autoFocus?: boolean;
};

/**
 * Inline side drawer (`cf-drawer`, 400px): sits beside the list, which stays usable.
 * Esc inside it calls `onClose`. Also the static shape for the gallery.
 */
export function DrawerPanel({
    title,
    subtitle,
    media,
    figure,
    children,
    closeLabel = 'Close details',
    onClose,
    autoFocus = false,
    className,
    style,
    ...rest
}: DrawerPanelProps) {
    const ref = useRef<HTMLElement>(null);

    useEffect(() => {
        if (autoFocus) {
            ref.current?.focus();
        }
    }, [autoFocus]);

    const onKeyDown = (event: KeyboardEvent<HTMLElement>) => {
        if (event.key === 'Escape' && onClose) {
            event.stopPropagation();
            onClose();
        }
    };

    return (
        <aside
            ref={ref}
            className={cn('cf-drawer', className)}
            aria-label={rest['aria-label']}
            tabIndex={autoFocus ? -1 : undefined}
            onKeyDown={onKeyDown}
            style={style}
        >
            <DrawerHead
                titleNode={<span style={TITLE_STYLE}>{title}</span>}
                subtitle={subtitle}
                media={media}
                figure={figure}
                close={<CloseButton label={closeLabel} onClick={onClose} />}
            />
            <div className="cf-drawer-body">{children}</div>
        </aside>
    );
}

const SMALL_QUERY = '(max-width: 767px)';

function subscribeSmall(callback: () => void) {
    const media = window.matchMedia(SMALL_QUERY);
    media.addEventListener('change', callback);

    return () => media.removeEventListener('change', callback);
}

const useSmallScreen = () =>
    useSyncExternalStore(
        subscribeSmall,
        () => window.matchMedia(SMALL_QUERY).matches,
        () => false,
    );

export type DrawerProps = DrawerLookProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Floating side drawer (`cf-drawer--float`) over the page, sliding in from the right.
 * On wide screens the page stays usable and an outside click closes it; on small
 * screens it adds the scrim and traps focus. Esc closes; focus returns to the opener.
 */
export function Drawer({
    open,
    onOpenChange,
    title,
    subtitle,
    media,
    figure,
    children,
    closeLabel = 'Close details',
    className,
    style,
}: DrawerProps) {
    const small = useSmallScreen();
    const descriptionId = useId();

    return (
        <DialogPrimitive.Root
            open={open}
            onOpenChange={onOpenChange}
            modal={small}
        >
            <DialogPrimitive.Portal>
                {small && <DialogPrimitive.Overlay className={SCRIM_CLASS} />}
                <DialogPrimitive.Content
                    className={cn(
                        'cf-drawer cf-drawer--float fixed max-w-[calc(100vw-40px)] focus-visible:shadow-pop max-sm:top-3 max-sm:right-3 max-sm:bottom-3 max-sm:max-w-[calc(100vw-24px)]',
                        className,
                    )}
                    style={style}
                    aria-describedby={subtitle ? descriptionId : undefined}
                >
                    <DrawerHead
                        titleNode={
                            <DialogPrimitive.Title asChild>
                                <span style={TITLE_STYLE}>{title}</span>
                            </DialogPrimitive.Title>
                        }
                        subtitle={
                            subtitle && (
                                <span id={descriptionId}>{subtitle}</span>
                            )
                        }
                        media={media}
                        figure={figure}
                        close={
                            <DialogPrimitive.Close asChild>
                                <IconButton
                                    icon={X}
                                    variant="glass"
                                    iconSize={16}
                                    aria-label={closeLabel}
                                />
                            </DialogPrimitive.Close>
                        }
                    />
                    <div className="cf-drawer-body min-h-0 flex-1 overflow-y-auto">
                        {children}
                    </div>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
