import { useState } from 'react';
import { PvNote } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { CodeInput } from '@/components/ui/code-input';

/** Live: 482613 is accepted, anything else fails and the boxes clear. */
function TypingDemo() {
    const [verifying, setVerifying] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [done, setDone] = useState(false);

    return (
        <>
            <CodeInput
                legend="Typing · focus on the 4th box"
                defaultValue="482"
                verifying={verifying}
                error={error}
                help={done ? 'Code accepted.' : undefined}
                onChange={() => {
                    setError(undefined);
                    setDone(false);
                }}
                onComplete={(code) => {
                    setVerifying(true);
                    window.setTimeout(() => {
                        setVerifying(false);

                        if (code === '482613') {
                            setDone(true);
                        } else {
                            setError(
                                'That code didn’t match. Codes change every 30 seconds.',
                            );
                        }
                    }, 1000);
                }}
            />
            <PvNote>Try 482613, or paste a whole code into any box.</PvNote>
        </>
    );
}

export default {
    name: 'CodeInput',
    group: 'Forms',
    order: 8,
    subtitle: '2FA and reveal checks · one box per digit',
    render: () => (
        // The preview card shrinks the boxes to 44 × 52 to fit; that's layout only.
        <div className="contents [&_.cf-code-box]:h-13 [&_.cf-code-box]:w-11">
            <TypingDemo />
            <CodeInput
                legend="Error"
                defaultValue="119007"
                clearOnError={false}
                error="That code didn’t match. Codes change every 30 seconds."
            />
            <CodeInput defaultValue="482613" verifying />
        </div>
    ),
} satisfies GallerySection;
