import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/shell/page-header';
import {
    MapStep,
    PreviewStep,
    UploadStep,
} from '@/components/timesheets/import-steps';
import { MonthlyHours } from '@/components/timesheets/monthly-hours';
import { Stepper } from '@/components/ui/stepper';
import { LinkTab, LinkTabs } from '@/components/ui/tabs';
import {
    importMethod as importPage,
    index as timesheetsIndex,
} from '@/routes/timesheets';
import type { ImportData, ImportStep } from '@/types/timesheet-import';
import type { TimesheetOptions } from '@/types/timesheets';

type Props = {
    company: { name: string; workingDays: number };
    today: string;
    tab: 'csv' | 'manual';
    step: ImportStep;
    import: ImportData | null;
    options: TimesheetOptions;
};

const STEPS: ImportStep[] = ['upload', 'map', 'preview'];

/**
 * Timesheets › Bulk import (BulkImport.dc.html, spec §8.2): From CSV in three steps
 * (Upload → Map columns → Preview & import), or Monthly hours by hand.
 */
export default function TimesheetImport({
    company,
    today,
    tab,
    step,
    import: data,
    options,
}: Props) {
    return (
        <>
            <Head title="Bulk import" />
            <PageHeader
                kicker="Timesheets"
                title="Bulk import"
                crumbs={[
                    { title: 'Timesheets', href: timesheetsIndex().url },
                    { title: 'Bulk import' },
                ]}
                actions={
                    <LinkTabs aria-label="Import method">
                        <LinkTab
                            href={importPage().url}
                            current={tab === 'csv'}
                        >
                            From CSV
                        </LinkTab>
                        <LinkTab
                            href={importPage({ query: { tab: 'manual' } }).url}
                            current={tab === 'manual'}
                        >
                            Monthly hours by hand
                        </LinkTab>
                    </LinkTabs>
                }
            />

            {tab === 'manual' ? (
                <MonthlyHours
                    options={options}
                    today={today}
                    workingDays={company.workingDays}
                />
            ) : (
                <>
                    <Stepper
                        aria-label="Import steps"
                        steps={['Upload', 'Map columns', 'Preview & import']}
                        current={STEPS.indexOf(step)}
                    />
                    {step === 'upload' && <UploadStep />}
                    {step === 'map' && data && (
                        <MapStep key={data.id} data={data} />
                    )}
                    {step === 'preview' && data && <PreviewStep data={data} />}
                </>
            )}
        </>
    );
}
