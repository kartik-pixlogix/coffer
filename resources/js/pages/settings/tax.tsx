import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { SettingsShell } from '@/components/settings/settings-shell';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { update as updateTax } from '@/routes/settings/tax';

type Option = { value: string; label: string };

type TaxProfile = {
    gstRegistration: string;
    stateCode: string | null;
    gstin: string | null;
    tan: string | null;
    gstFiledBy: string;
};

type Props = {
    tax: TaxProfile;
    options: { registrations: Option[]; states: Option[]; filedBy: Option[] };
};

type Form = {
    gst_registration: string;
    state_code: string;
    gstin: string;
    tan: string;
    gst_filed_by: string;
};

const toForm = (tax: TaxProfile): Form => ({
    gst_registration: tax.gstRegistration,
    state_code: tax.stateCode ?? '',
    gstin: tax.gstin ?? '',
    tan: tax.tan ?? '',
    gst_filed_by: tax.gstFiledBy,
});

/**
 * Settings › Tax (D159): the GST registration (type, state, GSTIN), the TAN for TDS and
 * who files the returns. No design board covers it (v2): it follows Settings › Company.
 */
export default function TaxSettings({ tax, options }: Props) {
    const [form, setForm] = useState<Form>(() => toForm(tax));
    const [base, setBase] = useState<Form>(() => toForm(tax));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setForm(toForm(tax));
        setBase(toForm(tax));
    }, [tax]);

    const dirty = (Object.keys(form) as (keyof Form)[]).some(
        (key) => form[key] !== base[key],
    );
    const registered = form.gst_registration !== 'unregistered';
    const set = (key: keyof Form, value: string) =>
        setForm((f) => ({ ...f, [key]: value }));

    const save = () =>
        router.put(
            updateTax().url,
            {
                ...form,
                state_code: form.state_code === '' ? null : form.state_code,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (e) => setErrors(e),
                onSuccess: () => setErrors({}),
            },
        );

    return (
        <SettingsShell
            tab="tax"
            title="Tax"
            crumb="Tax"
            actions={
                <>
                    {dirty && (
                        <Button variant="ghost" onClick={() => setForm(base)}>
                            Discard
                        </Button>
                    )}
                    <Button
                        variant="primary"
                        size="lg"
                        disabled={!dirty}
                        loading={saving}
                        onClick={save}
                        icon={Check}
                    >
                        Save changes
                    </Button>
                </>
            }
        >
            <Head title="Tax settings" />
            <form
                className="co-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    save();
                }}
            >
                <section className="cf-card co-card" aria-labelledby="gst-h">
                    <h2 id="gst-h" className="cf-dot-title t-title">
                        GST registration
                    </h2>
                    <div className="co-grid">
                        <Select
                            label="Registration"
                            value={form.gst_registration}
                            onChange={(e) =>
                                set('gst_registration', e.target.value)
                            }
                            options={options.registrations}
                            error={errors.gst_registration}
                            help="Composition dealers charge no GST and claim no input credit."
                        />
                        <Select
                            label="State"
                            value={form.state_code}
                            onChange={(e) => set('state_code', e.target.value)}
                            options={options.states}
                            placeholder="Choose a state"
                            error={errors.state_code}
                            help="Decides CGST + SGST or IGST on each bill."
                        />
                        <TextInput
                            label="GSTIN"
                            value={form.gstin}
                            onChange={(e) =>
                                set('gstin', e.target.value.toUpperCase())
                            }
                            maxLength={15}
                            className="t-mono"
                            placeholder={
                                registered ? '27ABCDE1234F1Z5' : 'Optional'
                            }
                            error={errors.gstin}
                            help="15 characters; the first two are the state code."
                        />
                        <Select
                            label="Returns are filed by"
                            value={form.gst_filed_by}
                            onChange={(e) =>
                                set('gst_filed_by', e.target.value)
                            }
                            options={options.filedBy}
                            error={errors.gst_filed_by}
                        />
                    </div>
                </section>

                <section className="cf-card co-card" aria-labelledby="tds-h">
                    <h2 id="tds-h" className="cf-dot-title t-title">
                        TDS
                    </h2>
                    <div className="co-grid">
                        <TextInput
                            label="TAN"
                            value={form.tan}
                            onChange={(e) =>
                                set('tan', e.target.value.toUpperCase())
                            }
                            maxLength={10}
                            className="t-mono"
                            placeholder="PNEV01234B"
                            error={errors.tan}
                            help="Needed to deposit TDS and file the quarterly returns. Stored encrypted."
                        />
                    </div>
                    <p className="t-caption t-muted m-0">
                        Rates and sections follow the Income-tax Act; have your
                        CA confirm them before you file.
                    </p>
                </section>
            </form>
        </SettingsShell>
    );
}
