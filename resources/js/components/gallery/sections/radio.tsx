import { PvGrid } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { FieldError } from '@/components/ui/field';
import { Radio, RadioGroup } from '@/components/ui/radio';

export default {
    name: 'Radio',
    group: 'Forms',
    order: 5,
    subtitle: 'One choice from a short list, all options visible',
    render: (theme) => (
        <PvGrid
            columns="repeat(2,minmax(0,1fr))"
            style={{ alignItems: 'start' }}
        >
            <RadioGroup
                legend="Income attribution"
                defaultValue="partner"
                options={[
                    { value: 'partner', label: 'Per partner' },
                    {
                        value: 'pooled',
                        label: 'JV pooled (hover)',
                        className: 'is-hover',
                    },
                    { value: 'focus', label: 'Focus', className: 'is-focus' },
                ]}
            />
            <fieldset
                className="cf-field"
                style={{ border: 0, padding: 0, margin: 0, gap: 10 }}
            >
                <legend className="cf-label" style={{ marginBottom: 8 }}>
                    Disabled and error
                </legend>
                <Radio name={`cur-${theme}`} disabled defaultChecked>
                    INR (locked after first entry)
                </Radio>
                <Radio name={`role-${theme}`} invalid>
                    Admin
                </Radio>
                <Radio name={`role-${theme}`} invalid>
                    Partner
                </Radio>
                <FieldError>Choose a role for Ishita.</FieldError>
            </fieldset>
        </PvGrid>
    ),
} satisfies GallerySection;
