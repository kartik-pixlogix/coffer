import { formatDate, formatInr } from '@/lib/format';
import type { ScheduleNote } from '@/types/reports';

/** A Schedule III figure: "—" for nothing, a true minus for a negative. */
export function figure(paise: number | null): string {
    return paise === null ? '' : paise === 0 ? '—' : formatInr(paise);
}

/** "1 Apr 2025 – 25 Nov 2025" or "31 Mar 2026". */
export function periodLabel(from: string | null, to: string | null): string {
    if (to === null) {
        return '';
    }

    return from === null
        ? formatDate(to)
        : `${formatDate(from)} – ${formatDate(to)}`;
}

/**
 * The numbered notes under a Schedule III statement (D156): each lists the accounts behind
 * one line, this period and the previous one.
 */
export function ScheduleNotes({
    notes,
    current,
    previous,
}: {
    notes: ScheduleNote[];
    current: string;
    previous: string | null;
}) {
    return (
        <section className="cf-card sm-card" aria-labelledby="notes-h">
            <h2 id="notes-h" className="cf-dot-title t-title">
                Notes
            </h2>
            <div className="s3-notes">
                {notes.map((note) => (
                    <div key={note.number} className="s3-note">
                        <h3 className="t-label-strong m-0">
                            {note.number}. {note.title}
                        </h3>
                        {note.subtitle && (
                            <p className="t-caption t-muted m-0">
                                {note.subtitle}
                            </p>
                        )}
                        <table className="s3-table">
                            <thead>
                                <tr>
                                    <th scope="col">Account</th>
                                    <th scope="col" className="s3-num">
                                        {current}
                                    </th>
                                    {previous !== null && (
                                        <th scope="col" className="s3-num">
                                            {previous}
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {note.rows.length === 0 && (
                                    <tr>
                                        <td
                                            className="t-muted"
                                            colSpan={previous === null ? 2 : 3}
                                        >
                                            Nothing in this period.
                                        </td>
                                    </tr>
                                )}
                                {note.rows.map((row) => (
                                    <tr key={`${row.code}-${row.name}`}>
                                        <td>
                                            {row.code && (
                                                <span className="t-caption sm-code">
                                                    {row.code}{' '}
                                                </span>
                                            )}
                                            {row.name}
                                        </td>
                                        <td className="s3-num t-money-sm">
                                            {figure(row.amount)}
                                        </td>
                                        {previous !== null && (
                                            <td className="s3-num t-money-sm">
                                                {figure(row.previous)}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row">Total</th>
                                    <td className="s3-num t-money-sm">
                                        {figure(note.total)}
                                    </td>
                                    {previous !== null && (
                                        <td className="s3-num t-money-sm">
                                            {figure(note.previousTotal)}
                                        </td>
                                    )}
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                ))}
            </div>
        </section>
    );
}
