import { Head, usePage } from '@inertiajs/react';
import { Calendar, LayoutGrid, Plus } from 'lucide-react';
import { PageHeader } from '@/components/shell/page-header';

/** "FY 2026-27" for the fiscal year (April–March) containing `date`. */
function fiscalYear(date: Date): string {
    const start =
        date.getMonth() >= 3 ? date.getFullYear() : date.getFullYear() - 1;

    return `FY ${start}-${String((start + 1) % 100).padStart(2, '0')}`;
}

export default function Dashboard() {
    const { auth } = usePage().props;
    const firstName = auth.user.name.trim().split(/\s+/u)[0] ?? '';

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader
                kicker={`Hi ${firstName},`}
                title="Dashboard"
                actions={
                    <>
                        <button
                            type="button"
                            className="cf-btn cf-btn--lg cf-btn--muted"
                            disabled
                            aria-label={`Period: ${fiscalYear(new Date())} (period picker coming soon)`}
                        >
                            <Calendar
                                size={18}
                                strokeWidth={1.5}
                                aria-hidden="true"
                            />
                            {fiscalYear(new Date())}
                        </button>
                        <button
                            type="button"
                            className="cf-btn cf-btn--lg cf-btn--primary cf-btn--disc"
                            disabled
                            aria-label="New company (available soon)"
                        >
                            New company
                            <span className="cf-disc" aria-hidden="true">
                                <Plus size={18} strokeWidth={1.75} />
                            </span>
                        </button>
                    </>
                }
            />

            <section className="cf-empty" aria-labelledby="dashboard-empty">
                <span className="cf-empty-ic">
                    <LayoutGrid
                        size={24}
                        strokeWidth={1.5}
                        aria-hidden="true"
                    />
                </span>
                <h2
                    id="dashboard-empty"
                    className="t-heading m-0 text-[20px] leading-6"
                >
                    Your dashboard is on its way
                </h2>
                <p className="t-body t-muted m-0 max-w-[420px]">
                    Balance, income, expenses and settlements appear here once
                    your company's books are set up.
                </p>
            </section>
        </>
    );
}
