import type { ReactNode } from 'react';
import type { GallerySection } from '@/components/gallery/types';
import { ProgressBar } from '@/components/ui/progress-bar';

function Meter({
    caption,
    figure,
    children,
}: {
    caption: string;
    figure: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col" style={{ gap: 6 }}>
            <div className="cf-kv t-caption">
                <span>{caption}</span>
                <span className="t-money-sm">{figure}</span>
            </div>
            {children}
        </div>
    );
}

export default {
    name: 'ProgressBar',
    group: 'Data and charts',
    order: 3,
    subtitle: 'Loan repayment, equity split, usage meters',
    render: () => (
        <>
            <Meter caption="Repaid · default" figure="₹1,20,000 of ₹3,00,000">
                <ProgressBar
                    aria-label="Loan repaid"
                    value={120000}
                    max={300000}
                    aria-valuetext="₹1,20,000 of ₹3,00,000"
                />
            </Meter>
            <Meter caption="Equity · lime" figure="50.00%">
                <ProgressBar
                    aria-label="Aarav equity"
                    value={50}
                    variant="lime"
                />
            </Meter>
            <Meter caption="Usage near limit · warning" figure="9 of 10 users">
                <ProgressBar
                    aria-label="Users used"
                    value={90}
                    variant="warn"
                />
            </Meter>
            <Meter caption="Over limit · negative" figure="104%">
                <ProgressBar
                    aria-label="Vault items used"
                    value={104}
                    variant="neg"
                />
            </Meter>
            <Meter caption="Thin · loading" figure="…">
                <ProgressBar aria-label="Loading" value={0} thin loading />
            </Meter>
        </>
    ),
} satisfies GallerySection;
