import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Moon, Sun } from 'lucide-react';
import { useState } from 'react';
import { PlanLimitBanner } from '@/components/billing/billing-banners';
import { UpgradeHost } from '@/components/billing/upgrade-host';
import { StepCompany } from '@/components/companies/step-company';
import { StepReview } from '@/components/companies/step-review';
import { StepTeam } from '@/components/companies/step-team';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { Button, ButtonLink } from '@/components/ui/button';
import { IconButton } from '@/components/ui/icon-button';
import { Stepper } from '@/components/ui/stepper';
import { useTheme } from '@/hooks/use-theme';
import {
    initialState,
    stepForError,
    stepOneValid,
    stepTwoValid,
    toPayload,
} from '@/lib/company-wizard';
import type { WizardState } from '@/lib/company-wizard';
import { home } from '@/routes';
import {
    check as checkStep,
    draft as saveDraft,
    store as launch,
} from '@/routes/companies';

type Step = 1 | 2 | 3;

type Props = {
    workspace: { name: string };
    owner: { name: string; email: string };
    suggestedName: string;
    hasCompanies: boolean;
    draft: { step: Step; data: WizardState } | null;
    currencies: string[];
};

const HEADINGS: Record<Step, [string, string]> = {
    1: ['Create company', 'Company & partners'],
    2: ['Optional', 'Add your team'],
    3: ['Almost there', 'Review & launch'],
};

/** Two-letter tile for the review card ("SP"). */
function initialsOf(name: string, code: string): string {
    const words = name.trim().split(/\s+/u).filter(Boolean);

    return (
        words
            .slice(0, 2)
            .map((w) => w[0])
            .join('') || code.slice(0, 2)
    ).toUpperCase();
}

/**
 * The create-company wizard (design/screens/CreateCompanySingle, CreateCompanyJV,
 * CreateCompanySteps; spec §3.1): company & partners with live 100% checks, an
 * optional team, then review and launch in one transaction. "Save and exit" keeps a draft.
 */
export default function CreateCompany({
    workspace,
    owner,
    suggestedName,
    hasCompanies,
    draft,
    currencies,
}: Props) {
    const [state, setState] = useState<WizardState>(
        () => draft?.data ?? initialState(owner, suggestedName),
    );
    const [step, setStep] = useState<Step>(draft?.step ?? 1);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const { theme, toggleTheme } = useTheme();
    const themeLabel =
        theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';

    const go = (next: Step) => {
        setStep(next);
        window.scrollTo({ top: 0 });
    };

    const next = () => {
        if (step === 3) {
            return;
        }

        router.post(checkStep().url, toPayload(state, step === 1 ? 1 : 2), {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setErrors({});
                go(step === 1 ? 2 : 3);
            },
            onError: (bag) => setErrors(bag),
        });
    };

    const submit = () => {
        router.post(launch().url, toPayload(state), {
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: (bag) => {
                setErrors(bag);
                const first = Object.keys(bag)[0];

                if (first !== undefined) {
                    go(stepForError(first));
                }
            },
        });
    };

    const saveAndExit = () => {
        router.put(
            saveDraft().url,
            { step, data: state },
            { preserveScroll: true },
        );
    };

    const canContinue =
        step === 1
            ? stepOneValid(state)
            : step === 2
              ? stepTwoValid(state)
              : true;
    const [kicker, title] = HEADINGS[step];
    const crumbsRoot =
        step === 1 ? workspace.name : state.name || workspace.name;

    return (
        <div className="cf-page">
            <Head title={`New company · ${title}`} />
            {/* Outside the app shell, so the wizard mounts the Upgrade modal itself (D82). */}
            <UpgradeHost />
            <div className="wiz-frame">
                <header className="cf-topbar">
                    <Link href={home()} className="cf-wordmark auth-wordmark">
                        <span className="cf-rail-logo" aria-hidden="true">
                            C
                        </span>
                        Coffer
                    </Link>
                    <Stepper
                        aria-label="Create company"
                        steps={[
                            'Company & partners',
                            'Team',
                            'Review & launch',
                        ]}
                        current={step - 1}
                    />
                    <div className="cf-topbar-r">
                        <Button variant="ghost" onClick={saveAndExit}>
                            Save and exit
                        </Button>
                        <IconButton
                            icon={theme === 'dark' ? Sun : Moon}
                            size="lg"
                            aria-label={themeLabel}
                            title={themeLabel}
                            onClick={toggleTheme}
                        />
                    </div>
                </header>

                <section className="cf-pagehead">
                    <div>
                        <Breadcrumb
                            items={[
                                { label: crumbsRoot },
                                { label: 'New company' },
                                { label: `Step ${step} of 3` },
                            ]}
                        />
                        <h1 className="cf-h1">
                            <span className="t-display-light">{kicker}</span>
                            <span className="t-display">
                                {step === 1 && state.type === 'jv'
                                    ? 'Joint venture'
                                    : title}
                            </span>
                        </h1>
                    </div>
                    {step === 2 && (
                        <Button
                            variant="ghost"
                            size="lg"
                            onClick={() => go(3)}
                            disabled={!stepTwoValid(state)}
                        >
                            Skip for now
                        </Button>
                    )}
                </section>

                <PlanLimitBanner />

                {step === 1 && (
                    <StepCompany
                        state={state}
                        setState={setState}
                        errors={errors}
                        ownerEmail={owner.email}
                        currencies={currencies}
                    />
                )}
                {step === 2 && (
                    <StepTeam
                        state={state}
                        setState={setState}
                        errors={errors}
                    />
                )}
                {step === 3 && (
                    <StepReview
                        state={state}
                        initials={initialsOf(state.name, state.shortCode)}
                        onEdit={go}
                    />
                )}

                <footer className="wiz-foot">
                    {step === 1 ? (
                        hasCompanies ? (
                            <ButtonLink
                                href={home().url}
                                variant="secondary"
                                size="lg"
                                icon={ArrowLeft}
                            >
                                Cancel
                            </ButtonLink>
                        ) : (
                            <span />
                        )
                    ) : (
                        <Button
                            variant="secondary"
                            size="lg"
                            icon={ArrowLeft}
                            onClick={() => go(step === 3 ? 2 : 1)}
                        >
                            Back
                        </Button>
                    )}

                    {step === 3 ? (
                        <Button
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={busy}
                            onClick={submit}
                        >
                            Launch {state.name}
                        </Button>
                    ) : (
                        <span className="cf-row">
                            {!canContinue && step === 1 && (
                                <span className="t-caption t-muted">
                                    {state.type === 'jv'
                                        ? 'Every total must be 100% to continue'
                                        : 'Equity must total 100% to continue'}
                                </span>
                            )}
                            <Button
                                variant="primary"
                                size="lg"
                                disc={ArrowRight}
                                loading={busy}
                                disabled={!canContinue}
                                onClick={next}
                            >
                                {step === 1 ? 'Next: team' : 'Next: review'}
                            </Button>
                        </span>
                    )}
                </footer>
            </div>
        </div>
    );
}
