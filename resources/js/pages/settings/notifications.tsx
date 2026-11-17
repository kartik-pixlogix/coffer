import { Head, router } from '@inertiajs/react';
import { Bell, Mail } from 'lucide-react';
import { useState } from 'react';
import { SettingsShell } from '@/components/settings/settings-shell';
import { Icon } from '@/components/ui/icon';
import { Toggle } from '@/components/ui/toggle';
import { update as updateRoute } from '@/routes/settings/notifications';
import type { NotificationPreferenceGroup } from '@/types';

type Channel = 'database' | 'mail';

type Props = {
    groups: NotificationPreferenceGroup[];
    email: string;
};

type Choices = Record<string, Record<Channel, boolean>>;

const CHANNELS: Array<{ key: Channel; label: string }> = [
    { key: 'database', label: 'In app' },
    { key: 'mail', label: 'Email' },
];

/**
 * Settings › Notifications (spec §16, D78): per event, In app (the bell and the
 * Notifications page) and Email, grouped by the panel's categories, with a switch per
 * category and channel that sets the whole group. Changes save at once. It's per user,
 * so everyone in the company can open it.
 */
export default function NotificationSettings({ groups, email }: Props) {
    const initial: Choices = Object.fromEntries(
        groups.flatMap((g) =>
            g.events.map((e) => [
                e.key,
                { database: e.database, mail: e.mail },
            ]),
        ),
    );
    const [choices, setChoices] = useState<Choices>(initial);
    const [saving, setSaving] = useState<string | null>(null);

    const required = (event: string, channel: Channel) =>
        groups
            .flatMap((g) => g.events)
            .find((e) => e.key === event)
            ?.required.includes(channel) ?? false;

    const save = (next: Choices, key: string) => {
        const previous = choices;
        setChoices(next);
        setSaving(key);
        router.put(
            updateRoute().url,
            { preferences: next },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => setChoices(previous),
                onFinish: () => setSaving(null),
            },
        );
    };

    const setOne = (event: string, channel: Channel, on: boolean) =>
        save(
            { ...choices, [event]: { ...choices[event], [channel]: on } },
            `${event}.${channel}`,
        );

    const setGroup = (
        group: NotificationPreferenceGroup,
        channel: Channel,
        on: boolean,
    ) => {
        const next = { ...choices };

        for (const e of group.events) {
            next[e.key] = {
                ...next[e.key],
                [channel]: on || required(e.key, channel),
            };
        }

        save(next, `${group.key}.${channel}`);
    };

    return (
        <SettingsShell
            tab="notifications"
            title="Notifications"
            crumb="Notifications"
        >
            <Head title="Notification settings" />

            <section
                className="cf-card flex flex-col gap-1"
                aria-labelledby="np-h"
            >
                <div className="cf-kv flex-wrap gap-2">
                    <h2 id="np-h" className="cf-dot-title t-title">
                        What you hear about
                    </h2>
                    <span className="t-caption t-muted">
                        Just for you · emails go to {email}
                    </span>
                </div>
                <p className="t-body t-muted m-0">
                    In app is the bell and the Notifications page. Email uses
                    your account address. A failed payment is always emailed to
                    billing contacts.
                </p>
            </section>

            {groups.map((group) => {
                const all = (channel: Channel) =>
                    group.events.every((e) => choices[e.key][channel]);

                return (
                    <section
                        key={group.key}
                        className="cf-card np-group"
                        aria-labelledby={`np-${group.key}`}
                    >
                        <div className="np-row np-head">
                            <h2
                                id={`np-${group.key}`}
                                className="cf-dot-title t-title"
                            >
                                {group.label}
                            </h2>
                            {CHANNELS.map((c) => (
                                <div key={c.key} className="np-cell">
                                    <span className="t-caption t-muted np-chan">
                                        <Icon
                                            icon={
                                                c.key === 'mail' ? Mail : Bell
                                            }
                                            size={14}
                                        />
                                        {c.label}
                                    </span>
                                    <Toggle
                                        checked={all(c.key)}
                                        saving={
                                            saving === `${group.key}.${c.key}`
                                        }
                                        savingLabel=""
                                        onCheckedChange={(on) =>
                                            setGroup(group, c.key, on)
                                        }
                                    >
                                        <span className="cf-sr">
                                            {c.label}: all of {group.label}
                                        </span>
                                    </Toggle>
                                </div>
                            ))}
                        </div>
                        {group.events.map((event) => (
                            <div key={event.key} className="np-row">
                                <div className="cf-stack" style={{ gap: 2 }}>
                                    <span className="t-label-strong">
                                        {event.label}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {event.hint}
                                    </span>
                                </div>
                                {CHANNELS.map((c) => {
                                    const locked = event.required.includes(
                                        c.key,
                                    );

                                    return (
                                        <div key={c.key} className="np-cell">
                                            <Toggle
                                                checked={
                                                    choices[event.key][c.key]
                                                }
                                                disabled={locked}
                                                saving={
                                                    saving ===
                                                    `${event.key}.${c.key}`
                                                }
                                                savingLabel=""
                                                title={
                                                    locked
                                                        ? 'Always on'
                                                        : undefined
                                                }
                                                onCheckedChange={(on) =>
                                                    setOne(event.key, c.key, on)
                                                }
                                            >
                                                <span className="cf-sr">
                                                    {c.label}: {event.label}
                                                    {locked
                                                        ? ' (always on)'
                                                        : ''}
                                                </span>
                                            </Toggle>
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </section>
                );
            })}
        </SettingsShell>
    );
}
