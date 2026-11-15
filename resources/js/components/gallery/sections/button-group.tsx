import { LayoutGrid, List } from 'lucide-react';
import { PvCell, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { ButtonGroup, ButtonGroupButton } from '@/components/ui/button-group';

export default {
    name: 'ButtonGroup',
    group: 'Actions and navigation',
    order: 3,
    subtitle: 'Joined buttons for view switches and related actions',
    render: () => (
        <>
            <PvCell label="Default · one selected">
                <ButtonGroup aria-label="Chart range">
                    <ButtonGroupButton pressed={false}>Month</ButtonGroupButton>
                    <ButtonGroupButton pressed>Quarter</ButtonGroupButton>
                    <ButtonGroupButton pressed={false}>FY</ButtonGroupButton>
                </ButtonGroup>
            </PvCell>
            <PvCell label="Hover · focus · disabled">
                <ButtonGroup aria-label="Export">
                    <ButtonGroupButton pressed={false} className="is-hover">
                        PDF
                    </ButtonGroupButton>
                    <ButtonGroupButton pressed={false} className="is-focus">
                        Excel
                    </ButtonGroupButton>
                    <ButtonGroupButton pressed={false} disabled>
                        CSV
                    </ButtonGroupButton>
                </ButtonGroup>
            </PvCell>
            <PvCell label="Icon group · loading">
                <PvRow>
                    <ButtonGroup aria-label="View">
                        <ButtonGroupButton
                            pressed
                            icon={List}
                            aria-label="List view"
                        />
                        <ButtonGroupButton
                            pressed={false}
                            icon={LayoutGrid}
                            aria-label="Grid view"
                        />
                    </ButtonGroup>
                    <ButtonGroup aria-label="Day or month">
                        <ButtonGroupButton pressed={false}>
                            Day
                        </ButtonGroupButton>
                        <ButtonGroupButton pressed loading>
                            Month
                        </ButtonGroupButton>
                    </ButtonGroup>
                </PvRow>
            </PvCell>
        </>
    ),
} satisfies GallerySection;
