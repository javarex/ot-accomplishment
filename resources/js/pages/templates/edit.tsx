import { Head, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import FooterEditor from '@/components/footer-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/report-template';

type Template = {
    province: string;
    office_name: string;
    office_address: string;
    report_title: string;
    certification_statement: string;
    footer_text: string | null;
    left_logo_path: string | null;
    right_logo_path: string | null;
};
type PageData = { errors: Record<string, string>; flash: { status?: string } };

export default function TemplateEdit({ template }: { template: Template }) {
    const { errors, flash } = usePage<PageData>().props;
    const [values, setValues] = useState({
        province: template.province,
        office_name: template.office_name,
        office_address: template.office_address,
        report_title: template.report_title,
        certification_statement: template.certification_statement,
        footer_text: template.footer_text ?? '',
    });
    const [leftLogo, setLeftLogo] = useState<File | null>(null);
    const [rightLogo, setRightLogo] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    function submit(event: FormEvent) {
        event.preventDefault();
        router.post(
            update().url,
            { ...values, left_logo: leftLogo, right_logo: rightLogo },
            {
                forceFormData: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    }
    return (
        <>
            <Head title="Report Template" />
            <div className="mx-auto w-full max-w-4xl space-y-6 px-4 py-6 md:px-8 md:py-9">
                <div>
                    <p className="text-xs font-semibold tracking-[0.18em] text-primary uppercase">
                        Report configuration
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Report template
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        These details appear in your report previews and PDFs.
                    </p>
                </div>
                {flash?.status && (
                    <p className="rounded-md border bg-muted p-3 text-sm">
                        {flash.status}
                    </p>
                )}
                {Object.values(errors ?? {}).map((error, index) => (
                    <p key={index} className="text-sm text-destructive">
                        {error}
                    </p>
                ))}
                <form
                    onSubmit={submit}
                    className="grid gap-4 rounded-2xl border bg-card p-5 shadow-sm shadow-black/2 md:p-7"
                >
                    {(
                        [
                            'province',
                            'office_name',
                            'office_address',
                            'report_title',
                            'certification_statement',
                        ] as const
                    ).map((key) => (
                        <div key={key}>
                            <Label htmlFor={key}>
                                {key
                                    .replaceAll('_', ' ')
                                    .replace(/\b\w/g, (char) =>
                                        char.toUpperCase(),
                                    )}
                            </Label>
                            {[
                                'office_address',
                                'certification_statement',
                            ].includes(key) ? (
                                <textarea
                                    id={key}
                                    className="min-h-20 w-full rounded-md border bg-background p-2 text-sm"
                                    value={values[key]}
                                    onChange={(event) =>
                                        setValues({
                                            ...values,
                                            [key]: event.target.value,
                                        })
                                    }
                                />
                            ) : (
                                <Input
                                    id={key}
                                    value={values[key]}
                                    onChange={(event) =>
                                        setValues({
                                            ...values,
                                            [key]: event.target.value,
                                        })
                                    }
                                />
                            )}
                        </div>
                    ))}
                    <div>
                        <Label htmlFor="footer_text">Footer text</Label>
                        <FooterEditor
                            initialHtml={template.footer_text ?? ''}
                            onChange={(footer_text) =>
                                setValues((current) => ({
                                    ...current,
                                    footer_text,
                                }))
                            }
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="left_logo">Left logo</Label>
                            <Input
                                id="left_logo"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) =>
                                    setLeftLogo(event.target.files?.[0] ?? null)
                                }
                            />
                            {template.left_logo_path && (
                                <p className="text-xs text-muted-foreground">
                                    Current logo saved
                                </p>
                            )}
                        </div>
                        <div>
                            <Label htmlFor="right_logo">Right logo</Label>
                            <Input
                                id="right_logo"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) =>
                                    setRightLogo(
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            {template.right_logo_path && (
                                <p className="text-xs text-muted-foreground">
                                    Current logo saved
                                </p>
                            )}
                        </div>
                    </div>
                    <div>
                        <Button disabled={busy} type="submit">
                            Save Template
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
