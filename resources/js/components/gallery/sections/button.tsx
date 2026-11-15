import { ArrowRight, Plus, RotateCw } from 'lucide-react';
import { Fragment } from 'react';
import { PvGrid, PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Button, TextButton } from '@/components/ui/button';
import type { ButtonVariant } from '@/components/ui/button';

const STATES = ['Default', 'Hover', 'Focus', 'Disabled', 'Loading', 'Error'];

const ROWS: {
    label: string;
    variant: ButtonVariant;
    text: string;
    loading: string;
}[] = [
    {
        label: 'Primary',
        variant: 'primary',
        text: 'Resolve',
        loading: 'Saving…',
    },
    {
        label: 'Secondary',
        variant: 'secondary',
        text: 'Export',
        loading: 'Exporting…',
    },
    { label: 'Ghost', variant: 'ghost', text: 'Cancel', loading: 'Loading' },
    {
        label: 'Destructive',
        variant: 'danger',
        text: 'Delete',
        loading: 'Deleting…',
    },
];

const ERROR_TEXT: Record<string, string> = {
    Primary: 'Retry',
    Secondary: 'Export failed',
    Ghost: 'Cancel',
    Destructive: 'Delete',
};

export default {
    name: 'Button',
    group: 'Actions and navigation',
    order: 1,
    subtitle:
        'Primary pill with arrow disc, secondary, ghost, destructive · large, medium, small',
    render: () => (
        <>
            <PvRow>
                <Button variant="primary" size="lg" disc={Plus}>
                    New company
                </Button>
                <Button variant="primary" disc={ArrowRight}>
                    Save expense
                </Button>
                <Button variant="primary" size="sm" disc={Plus}>
                    Add
                </Button>
                <PvState>Large 54 · Medium 40 · Small 32</PvState>
            </PvRow>

            <PvGrid columns="110px repeat(6,minmax(0,1fr))">
                <span />
                {STATES.map((s) => (
                    <PvState key={s}>{s}</PvState>
                ))}
                {ROWS.map(({ label, variant, text, loading }) => {
                    const disc = variant === 'primary' ? ArrowRight : undefined;

                    return (
                        <Fragment key={label}>
                            <PvState>{label}</PvState>
                            <Button variant={variant} disc={disc}>
                                {text}
                            </Button>
                            <Button
                                variant={variant}
                                disc={disc}
                                className="is-hover"
                            >
                                {text}
                            </Button>
                            <Button
                                variant={variant}
                                disc={disc}
                                className="is-focus"
                            >
                                {text}
                            </Button>
                            <Button variant={variant} disc={disc} disabled>
                                {text}
                            </Button>
                            <Button variant={variant} disc={disc} loading>
                                {loading}
                            </Button>
                            <Button
                                variant={variant}
                                disc={
                                    variant === 'primary' ? RotateCw : undefined
                                }
                                invalid
                            >
                                {ERROR_TEXT[label]}
                            </Button>
                        </Fragment>
                    );
                })}
            </PvGrid>

            <PvRow>
                <Button size="lg">Large secondary</Button>
                <Button size="sm">Small secondary</Button>
                <Button variant="ghost" size="sm" icon={Plus}>
                    Add payer
                </Button>
                <Button variant="danger" size="sm">
                    Reject
                </Button>
                <TextButton>Forgot password?</TextButton>
            </PvRow>
        </>
    ),
} satisfies GallerySection;
