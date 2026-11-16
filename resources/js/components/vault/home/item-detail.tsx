import { Copy, Eye, KeyRound, ShieldCheck, Star } from 'lucide-react';
import { Avatar } from '@/components/ui/avatar';
import { AvatarStack, joinNames } from '@/components/ui/avatar-stack';
import { Button, buttonClass } from '@/components/ui/button';
import { DrawerPanel } from '@/components/ui/drawer';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Sheet } from '@/components/ui/sheet';
import {
    Skeleton,
    SkeletonGroup,
    SkeletonText,
} from '@/components/ui/skeleton';
import { StatusPill } from '@/components/ui/status-pill';
import type { StatusTone } from '@/components/ui/status-pill';
import { TotpCode, TotpRing } from '@/components/ui/totp-ring';
import {
    AccessBlock,
    ActivityBlock,
    AttachmentsBlock,
    copyValue,
    FieldRows,
    ItemActions,
    LoginLink,
    NotesBlock,
    partnerOf,
    rowsOf,
    StepUpFor,
} from '@/components/vault/home/item-parts';
import { useItemSecrets } from '@/components/vault/home/use-item-secrets';
import type { ItemSecrets } from '@/components/vault/home/use-item-secrets';
import { EnvPill, FlagPill } from '@/components/vault/home/vault-bits';
import {
    displayUrl,
    formatDateIn,
    itemFlags,
    safeHref,
    strengthView,
} from '@/components/vault/home/vault-home.utils';
import type {
    DetailRow,
    StrengthTone,
} from '@/components/vault/home/vault-home.utils';
import { useVaultSession } from '@/components/vault/home/vault-session';
import { firstName } from '@/lib/team';
import type { VaultItemDetail, VaultItemRow } from '@/types/vault';

const MASK = '••••••••••••••••';

type DetailProps = {
    item: VaultItemDetail;
    /** The list row's favourite flag (refreshed without re-reading the drawer). */
    favourite: boolean;
    phone: boolean;
    openShare: boolean;
    onClose: () => void;
    onFavourite: () => void;
    onDeleted: () => void;
};

/**
 * One vault item: the inline drawer beside the list (Vault.dc.html), or a full-height
 * sheet at phone width (MobileVault.dc.html). Mount it with `key={item.id}` so revealed
 * values never outlive the item they belong to.
 */
export function ItemDetail(props: DetailProps) {
    const secrets = useItemSecrets(props.item.id);

    return props.phone ? (
        <ItemSheet {...props} secrets={secrets} />
    ) : (
        <ItemDrawer {...props} secrets={secrets} />
    );
}

type ViewProps = DetailProps & { secrets: ItemSecrets };

function FavouriteButton({
    on,
    onClick,
    glass,
}: {
    on: boolean;
    onClick: () => void;
    glass: boolean;
}) {
    return (
        <IconButton
            icon={Star}
            size="sm"
            variant={glass ? 'glass' : 'default'}
            iconSize={15}
            pressed={on}
            className="vh-fav"
            aria-label="Favourite"
            onClick={onClick}
        />
    );
}

function TotpCard({ secrets }: { secrets: ItemSecrets }) {
    const { totp } = secrets;
    const period = totp?.period ?? 30;

    return (
        <div className="vh-totp">
            {totp ? (
                <TotpRing
                    tone="dark"
                    size={48}
                    period={totp.period}
                    expiresAt={totp.expiresAt}
                />
            ) : (
                <span className="vh-totp-idle" aria-hidden="true">
                    <Icon icon={KeyRound} size={18} />
                </span>
            )}
            <span className="vh-totp-text">
                <span className="t-caption vh-on-muted">
                    One-time code · refreshes every {period} s
                </span>
                {totp ? (
                    <span aria-live="off">
                        <TotpCode code={totp.code} />
                    </span>
                ) : (
                    <Button
                        variant="onDark"
                        size="sm"
                        icon={Eye}
                        loading={secrets.busy === 'totp'}
                        onClick={secrets.showCode}
                        className="vh-totp-show"
                    >
                        Show code
                    </Button>
                )}
            </span>
            {totp && (
                <IconButton
                    icon={Copy}
                    variant="glass"
                    iconSize={17}
                    aria-label="Copy one-time code"
                    onClick={() => void secrets.copyCode()}
                />
            )}
        </div>
    );
}

function HeadPills({ item }: { item: VaultItemDetail }) {
    const strength = strengthView(item);

    return (
        <>
            <EnvPill environment={item.environment} size="md" />
            {item.hasTotp && (
                <StatusPill tone="lime" icon={ShieldCheck}>
                    2FA on
                </StatusPill>
            )}
            <span className="cf-pill vh-pill-glass">{strength.label}</span>
            {itemFlags(item).map((flag) => (
                <FlagPill key={flag.key} flag={flag} />
            ))}
        </>
    );
}

function ItemDrawer({
    item,
    favourite,
    openShare,
    onClose,
    onFavourite,
    onDeleted,
    secrets,
}: ViewProps) {
    const { timeZone } = useVaultSession();

    return (
        <DrawerPanel
            aria-label={item.name}
            className="vh-drawer"
            title={item.name}
            subtitle={`${item.category} · ${item.vault} vault`}
            media={
                <Avatar
                    name={item.name}
                    initials={item.monogram}
                    size="lg"
                    tile
                    label={false}
                    className="vh-dtile"
                />
            }
            figure={
                <div className="vh-dfig">
                    <div className="vh-dpills">
                        <HeadPills item={item} />
                        <FavouriteButton
                            on={favourite}
                            onClick={onFavourite}
                            glass
                        />
                    </div>
                    {item.hasTotp && item.abilities.reveal && (
                        <TotpCard secrets={secrets} />
                    )}
                </div>
            }
            onClose={onClose}
            autoFocus
        >
            {!item.abilities.reveal && (
                <p className="t-caption t-muted m-0">
                    You can see this item but not its secrets.
                </p>
            )}
            <StepUpFor secrets={secrets} target="totp" action="show the code" />
            <FieldRows item={item} secrets={secrets} timeZone={timeZone} />
            <LoginLink url={item.url} />
            <NotesBlock item={item} secrets={secrets} />
            <AttachmentsBlock item={item} secrets={secrets} />
            <AccessBlock item={item} focus={openShare} />
            <ActivityBlock item={item} timeZone={timeZone} />
            <ItemActions item={item} onDeleted={onDeleted} />
            <span className="cf-sr" role="status">
                {secrets.announcement}
            </span>
        </DrawerPanel>
    );
}

/* ------------------------------------------------------------------ phone */

const STRENGTH_TONE: Record<StrengthTone, StatusTone> = {
    strong: 'pos',
    fair: 'warn',
    weak: 'neg',
    none: 'outline',
};

function PhoneRow({
    row,
    item,
    secrets,
}: {
    row: DetailRow;
    item: VaultItemDetail;
    secrets: ItemSecrets;
}) {
    const shown = row.secret ? secrets.shown(row.key) : null;
    const lower = row.label.toLocaleLowerCase('en-IN');
    const canUse = row.secret && row.set && item.abilities.reveal;

    return (
        <>
            <div className="vh-fld">
                <div className="cf-stack vh-fld-text">
                    <span className="t-caption t-muted">
                        {shown
                            ? `${row.label} · hides in ${shown.secondsLeft} s`
                            : row.label}
                    </span>
                    {!row.secret ? (
                        <span className="vh-fld-v">{row.value}</span>
                    ) : !row.set ? (
                        <span className="t-body t-muted">Not set</span>
                    ) : shown ? (
                        <span className="vh-fld-v">{shown.value}</span>
                    ) : (
                        <span className="vh-fld-v">
                            <span aria-hidden="true">{MASK}</span>
                            <span className="cf-sr">Hidden</span>
                        </span>
                    )}
                </div>
                {row.copyable && row.value !== null && (
                    <Button
                        size="sm"
                        aria-label={`Copy ${lower}`}
                        onClick={() =>
                            void copyValue(row.value ?? '', row.label)
                        }
                    >
                        Copy
                    </Button>
                )}
                {canUse &&
                    (shown ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => secrets.hide(row.key)}
                        >
                            Hide
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            loading={secrets.busy === row.key}
                            aria-label={`Reveal ${lower}`}
                            onClick={() => secrets.reveal(row.key)}
                        >
                            {secrets.busy === row.key ? 'Checking' : 'Reveal'}
                        </Button>
                    ))}
                {canUse && (
                    <Button
                        size="sm"
                        aria-label={`Copy ${lower}`}
                        onClick={() => secrets.copy(row.key)}
                    >
                        Copy
                    </Button>
                )}
            </div>
            <StepUpFor secrets={secrets} target={row.key} />
        </>
    );
}

function ItemSheet({
    item,
    favourite,
    openShare,
    onClose,
    onFavourite,
    onDeleted,
    secrets,
}: ViewProps) {
    const { timeZone, policy } = useVaultSession();
    const strength = strengthView(item);
    const rows = rowsOf(item);
    const primary = rows.find((r) => r.primary && r.secret);
    const primaryShown = primary ? secrets.shown(primary.key) : null;
    const href = item.url ? safeHref(item.url) : null;
    const { totp } = secrets;

    return (
        <Sheet
            open
            onOpenChange={(open) => !open && onClose()}
            title={item.name}
            closeLabel="Back to vault"
            footer={<ItemActions item={item} onDeleted={onDeleted} />}
        >
            <div className="vh-sheet">
                <section className="vh-m-id" aria-label="Item">
                    <Avatar
                        name={item.name}
                        initials={item.monogram}
                        size="xl"
                        tile
                        label={false}
                        className="vh-m-tile"
                    />
                    <div className="cf-stack vh-m-idtext">
                        <span className="t-caption t-muted">
                            {item.category} · {item.vault} vault
                        </span>
                        <div className="vh-dpills">
                            <EnvPill environment={item.environment} />
                            <StatusPill
                                tone={STRENGTH_TONE[strength.tone]}
                                size="sm"
                            >
                                {strength.label}
                            </StatusPill>
                            {item.hasTotp && (
                                <StatusPill
                                    tone="outline"
                                    icon={ShieldCheck}
                                    size="sm"
                                >
                                    2FA on
                                </StatusPill>
                            )}
                            {itemFlags(item).map((flag) => (
                                <FlagPill
                                    key={flag.key}
                                    flag={flag}
                                    size="sm"
                                />
                            ))}
                        </div>
                    </div>
                    <FavouriteButton
                        on={favourite}
                        onClick={onFavourite}
                        glass={false}
                    />
                </section>

                {item.hasTotp && item.abilities.reveal && (
                    <section
                        className="cf-card cf-card--graphite vh-m-totp"
                        aria-label="2FA code"
                    >
                        {totp ? (
                            <TotpRing
                                tone="dark"
                                size={56}
                                period={totp.period}
                                expiresAt={totp.expiresAt}
                            />
                        ) : (
                            <span className="vh-totp-idle" aria-hidden="true">
                                <Icon icon={KeyRound} size={18} />
                            </span>
                        )}
                        <div className="cf-stack vh-m-totp-text">
                            <span className="t-caption vh-on-muted">
                                2FA code
                            </span>
                            {totp ? (
                                <span aria-live="off">
                                    <TotpCode
                                        code={totp.code}
                                        className="vh-m-code"
                                    />
                                </span>
                            ) : (
                                <span className="t-caption vh-on-muted">
                                    Refreshes every 30 s while open
                                </span>
                            )}
                        </div>
                        {totp ? (
                            <Button
                                variant="lime"
                                size="sm"
                                aria-label="Copy one-time code"
                                onClick={() => void secrets.copyCode()}
                            >
                                Copy
                            </Button>
                        ) : (
                            <Button
                                variant="lime"
                                size="sm"
                                loading={secrets.busy === 'totp'}
                                onClick={secrets.showCode}
                            >
                                Show code
                            </Button>
                        )}
                    </section>
                )}
                <StepUpFor
                    secrets={secrets}
                    target="totp"
                    action="show the code"
                />

                {!item.abilities.reveal && (
                    <p className="t-caption t-muted m-0">
                        You can see this item but not its secrets.
                    </p>
                )}

                {(rows.length > 0 || href) && (
                    <section
                        className="cf-card vh-m-fields"
                        aria-label="Login details"
                    >
                        {rows.map((row) => (
                            <PhoneRow
                                key={row.key}
                                row={row}
                                item={item}
                                secrets={secrets}
                            />
                        ))}
                        {href && item.url && (
                            <div className="vh-fld">
                                <div className="cf-stack vh-fld-text">
                                    <span className="t-caption t-muted">
                                        Website
                                    </span>
                                    <span className="t-body vh-ellipsis">
                                        {displayUrl(item.url)}
                                    </span>
                                </div>
                                <a
                                    href={href}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={buttonClass({ size: 'sm' })}
                                    aria-label="Open login page"
                                >
                                    Open
                                </a>
                            </div>
                        )}
                    </section>
                )}
                {primary && item.abilities.reveal && (
                    <p className="t-caption t-muted vh-m-hint">
                        {primaryShown
                            ? 'Revealed. This is in the audit log.'
                            : `Reveal asks for your 2FA code, and shows the ${primary.label.toLocaleLowerCase('en-IN')} for ${policy.revealSeconds} seconds.`}
                    </p>
                )}

                {(item.notesSet || item.attachments.length > 0) && (
                    <section
                        className="cf-card vh-m-more"
                        aria-label="Notes and files"
                    >
                        <NotesBlock item={item} secrets={secrets} />
                        <AttachmentsBlock item={item} secrets={secrets} />
                    </section>
                )}

                <section
                    className="cf-card vh-m-card"
                    aria-labelledby="vh-m-sw"
                >
                    <h2 id="vh-m-sw" className="cf-dot-title t-title">
                        Shared with
                    </h2>
                    {openShare && <AccessBlock item={item} focus />}
                    {!openShare && (
                        <div className="cf-row" style={{ gap: 10 }}>
                            <AvatarStack
                                people={item.people.map((p) => ({
                                    name: p.name,
                                    partner: partnerOf(p),
                                }))}
                                max={4}
                                label={`Shared with ${joinNames(item.people.map((p) => p.name))}`}
                            />
                            <span className="t-caption t-muted">
                                {joinNames(
                                    item.people.map((p) =>
                                        p.you ? 'you' : firstName(p.name),
                                    ),
                                )}
                            </span>
                        </div>
                    )}
                </section>

                {item.activity.length > 0 && (
                    <section
                        className="cf-card vh-m-card"
                        aria-labelledby="vh-m-ac"
                    >
                        <h2 id="vh-m-ac" className="cf-dot-title t-title">
                            Recent activity
                        </h2>
                        {item.activity.map((event) => (
                            <div
                                key={event.id}
                                className="cf-kv t-body vh-m-event"
                            >
                                <span>{event.text}</span>
                                <span className="t-caption t-muted">
                                    {formatDateIn(event.at, timeZone)}
                                </span>
                            </div>
                        ))}
                    </section>
                )}
                <span className="cf-sr" role="status">
                    {secrets.announcement}
                </span>
            </div>
        </Sheet>
    );
}

/* ---------------------------------------------------------------- loading */

/** While the drawer's details load: the row's identity over skeleton lines. */
export function ItemLoading({
    row,
    phone,
    onClose,
}: {
    row: VaultItemRow;
    phone: boolean;
    onClose: () => void;
}) {
    const body = (
        <SkeletonGroup aria-label={`Loading ${row.name}`}>
            <div className="cf-stack" style={{ gap: 16 }}>
                {[0, 1, 2].map((i) => (
                    <span key={i} className="cf-stack" style={{ gap: 6 }}>
                        <Skeleton variant="line" width="30%" />
                        <Skeleton height={48} />
                    </span>
                ))}
                <SkeletonText lines={['60%', '80%', '50%']} />
            </div>
        </SkeletonGroup>
    );

    if (phone) {
        return (
            <Sheet
                open
                onOpenChange={(open) => !open && onClose()}
                title={row.name}
                closeLabel="Back to vault"
            >
                <div className="vh-sheet">{body}</div>
            </Sheet>
        );
    }

    return (
        <DrawerPanel
            aria-label={row.name}
            className="vh-drawer"
            title={row.name}
            subtitle={`${row.category} · ${row.vault} vault`}
            media={
                <Avatar
                    name={row.name}
                    initials={row.monogram}
                    size="lg"
                    tile
                    label={false}
                    className="vh-dtile"
                />
            }
            onClose={onClose}
        >
            {body}
        </DrawerPanel>
    );
}
