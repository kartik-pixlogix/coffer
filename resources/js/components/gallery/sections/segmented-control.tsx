import { PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { SegmentedControl } from '@/components/ui/segmented-control';

const noop = () => {};

export default {
    name: 'SegmentedControl',
    group: 'Forms',
    order: 20,
    subtitle: 'Full / Half / Custom / Direct company · lime selection',
    render: () => (
        <>
            <SegmentedControl
                label="Paid by · default, hover, selected"
                value="custom"
                onValueChange={noop}
                options={[
                    { value: 'full', label: 'Full' },
                    { value: 'half', label: 'Half', className: 'is-hover' },
                    { value: 'custom', label: 'Custom' },
                    { value: 'direct', label: 'Direct company' },
                ]}
            />
            <PvRow>
                <SegmentedControl
                    label="Focus · small"
                    size="sm"
                    value="month"
                    onValueChange={noop}
                    options={[
                        { value: 'day', label: 'Day', className: 'is-focus' },
                        { value: 'month', label: 'Month' },
                    ]}
                />
                <SegmentedControl
                    label="Disabled option"
                    size="sm"
                    value="hourly"
                    onValueChange={noop}
                    options={[
                        { value: 'hourly', label: 'Hourly' },
                        { value: 'fixed', label: 'Fixed monthly' },
                        {
                            value: 'project',
                            label: 'Per project',
                            disabled: true,
                        },
                    ]}
                />
            </PvRow>
        </>
    ),
} satisfies GallerySection;
