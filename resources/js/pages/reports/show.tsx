import { formatPeso } from '@/lib/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, generate, index, preview } from '@/routes/reports';
import { update as updateHourlyRate } from '@/routes/reports/hourly-rate';
import { update as updateDailyRate } from '@/routes/reports/daily-rate';

type Signatory = { name: string; position: string } | null;
type Report = {
    id: number;
    report_month: number;
    report_year: number;
    status: string;
    quantity_mode: 'custom' | 'time';
    hourly_rate: string | null;
    is_jo: boolean;
    daily_rate: string | null;
    jo_tax_percent: string;
    user: { name: string };
    prepared_name: string | null;
    prepared_position: string | null;
    certified_name: string | null;
    certified_position: string | null;
    approved_name: string | null;
    approved_position: string | null;
    prepared_by: Signatory;
    certified_by: Signatory;
    approved_by: Signatory;
    entries: Array<{
        id: number;
        accomplishment_date: string;
        quantity: string;
        task_accomplished: string | null;
    }>;
};

function SignatoryDetails({
    label,
    name,
    position,
}: {
    label: string;
    name: string;
    position: string;
}) {
    return (
        <div className="rounded-lg border p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-2 font-medium">{name || 'Not set'}</p>
            <p className="text-sm">{position}</p>
        </div>
    );
}

export default function ReportShow({
    report,
    canEdit,
    canGeneratePdf,
    overtimePay,
    canViewComputation,
    canSetHourlyRate,
}: {
    report: Report;
    canEdit: boolean;
    canGeneratePdf: boolean;
    canViewComputation: boolean;
    canSetHourlyRate: boolean;
    overtimePay: {
        complete: boolean;
        gross_cents: number;
        deduction_cents: number;
        net_cents: number;
    } | null;
}) {
    const { csrfToken } = usePage<{ csrfToken: string }>().props;
    const [hourlyRate, setHourlyRate] = useState(report.hourly_rate ?? '');
    const [dailyRate, setDailyRate] = useState(report.daily_rate ?? '');
    const [joTaxPercent, setJoTaxPercent] = useState(
        report.jo_tax_percent ?? '0',
    );
    const [savingRate, setSavingRate] = useState(false);

    function saveRate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.put(
            (report.is_jo
                ? updateDailyRate(report.id)
                : updateHourlyRate(report.id)
            ).url,
            report.is_jo
                ? { daily_rate: dailyRate, jo_tax_percent: joTaxPercent || '0' }
                : { hourly_rate: hourlyRate },
            {
                onStart: () => setSavingRate(true),
                onFinish: () => setSavingRate(false),
            },
        );
    }

    const period = new Date(
        report.report_year,
        report.report_month - 1,
        1,
    ).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

    return (
        <>
            <Head title={`${period} accomplishment report`} />
            <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 md:px-8 md:py-9">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link href={index()} className="text-sm underline">
                            All reports
                        </Link>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Accomplishment Report
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {period} · {report.user.name} · {report.status}
                            {report.is_jo ? ' · JO' : ''}
                        </p>
                        {!canEdit && (
                            <p className="mt-2 text-sm font-medium">
                                View only — only the owner can make changes.
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={preview(report.id)} target="_blank">
                                Print preview
                            </Link>
                        </Button>
                        {canGeneratePdf && (
                            <form
                                method="post"
                                action={generate(report.id).url}
                                target="_blank"
                            >
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrfToken}
                                />
                                <Button type="submit">Generate PDF</Button>
                            </form>
                        )}
                        {canEdit && (
                            <Button asChild>
                                <Link href={edit(report.id)}>Edit report</Link>
                            </Button>
                        )}
                    </div>
                </div>

                {canViewComputation &&
                    overtimePay &&
                    report.quantity_mode === 'time' && (
                        <section className="rounded-lg border bg-card p-4">
                            <p>
                                {report.is_jo ? 'Daily rate' : 'Hourly rate'}:{' '}
                                {formatPeso(
                                    report.is_jo
                                        ? report.daily_rate
                                        : report.hourly_rate,
                                )}
                            </p>
                            {report.is_jo && (
                                <p>JO tax: {report.jo_tax_percent ?? '0'}%</p>
                            )}
                            {canSetHourlyRate && (
                                <form
                                    onSubmit={saveRate}
                                    className="mt-3 flex max-w-xl flex-wrap items-end gap-2"
                                >
                                    <div className="min-w-40 flex-1">
                                        <Label htmlFor="report-rate">
                                            Set report{' '}
                                            {report.is_jo ? 'daily' : 'hourly'}{' '}
                                            rate
                                        </Label>
                                        <Input
                                            id="report-rate"
                                            type="number"
                                            min="0"
                                            max="99999999.99"
                                            step="0.01"
                                            required
                                            value={
                                                report.is_jo
                                                    ? dailyRate
                                                    : hourlyRate
                                            }
                                            onChange={(event) =>
                                                report.is_jo
                                                    ? setDailyRate(
                                                          event.target.value,
                                                      )
                                                    : setHourlyRate(
                                                          event.target.value,
                                                      )
                                            }
                                        />
                                    </div>
                                    {report.is_jo && (
                                        <div className="min-w-32 flex-1">
                                            <Label htmlFor="report-jo-tax">
                                                JO tax (%)
                                            </Label>
                                            <Input
                                                id="report-jo-tax"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value={joTaxPercent}
                                                onChange={(event) =>
                                                    setJoTaxPercent(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    )}
                                    <Button type="submit" disabled={savingRate}>
                                        Save{' '}
                                        {report.is_jo
                                            ? 'JO pay settings'
                                            : 'rate'}
                                    </Button>
                                </form>
                            )}
                            {report.is_jo ? (
                                <p>
                                    JO: daily rate ÷ 8 × total rendered hours ·
                                    100% on every day
                                </p>
                            ) : (
                                <p>
                                    Weekdays: hours × hourly rate × 125% ·
                                    Weekends: hours × hourly rate × 150%
                                </p>
                            )}
                            {overtimePay.complete ? (
                                <>
                                    <p>
                                        {report.is_jo
                                            ? 'Gross JO pay'
                                            : 'Gross OT pay'}
                                        :{' '}
                                        {formatPeso(
                                            overtimePay.gross_cents / 100,
                                        )}
                                    </p>
                                    {report.is_jo && (
                                        <p>
                                            JO tax (
                                            {report.jo_tax_percent ?? '0'}%):{' '}
                                            {formatPeso(
                                                overtimePay.deduction_cents /
                                                    100,
                                            )}
                                        </p>
                                    )}
                                    {report.is_jo && (
                                        <p>
                                            Net JO pay:{' '}
                                            {formatPeso(
                                                overtimePay.net_cents / 100,
                                            )}
                                        </p>
                                    )}
                                    {!report.is_jo && (
                                        <p>
                                            Deduction (20%):{' '}
                                            {formatPeso(
                                                overtimePay.deduction_cents /
                                                    100,
                                            )}
                                        </p>
                                    )}
                                    {!report.is_jo && (
                                        <p>
                                            Net OT pay:{' '}
                                            {formatPeso(
                                                overtimePay.net_cents / 100,
                                            )}
                                        </p>
                                    )}
                                </>
                            ) : (
                                <p>
                                    Enter a {report.is_jo ? 'daily' : 'hourly'}{' '}
                                    rate for the report to calculate pay.
                                </p>
                            )}
                        </section>
                    )}

                <section className="grid gap-3 rounded-2xl border bg-card p-5 shadow-sm shadow-black/2 md:grid-cols-3">
                    <SignatoryDetails
                        label="Prepared by"
                        name={
                            report.prepared_name ||
                            report.prepared_by?.name ||
                            ''
                        }
                        position={
                            report.prepared_position ||
                            report.prepared_by?.position ||
                            ''
                        }
                    />
                    <SignatoryDetails
                        label="Certified Correct"
                        name={
                            report.certified_name ||
                            report.certified_by?.name ||
                            ''
                        }
                        position={
                            report.certified_position ||
                            report.certified_by?.position ||
                            ''
                        }
                    />
                    <SignatoryDetails
                        label="Approved by"
                        name={
                            report.approved_name ||
                            report.approved_by?.name ||
                            ''
                        }
                        position={
                            report.approved_position ||
                            report.approved_by?.position ||
                            ''
                        }
                    />
                </section>

                <section className="overflow-x-auto rounded-2xl border bg-card shadow-sm shadow-black/2">
                    <table className="w-full min-w-[700px] text-left text-sm">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="p-3">Date</th>
                                <th className="p-3">Quantity</th>
                                {canViewComputation && (
                                    <th className="p-3">
                                        {report.is_jo
                                            ? 'Daily rate'
                                            : 'Hourly rate'}
                                    </th>
                                )}
                                <th className="p-3">Task Accomplished</th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.entries.map((entry) => (
                                <tr
                                    key={entry.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="p-3 whitespace-nowrap">
                                        {new Date(
                                            `${entry.accomplishment_date.slice(0, 10)}T12:00:00`,
                                        ).toLocaleDateString('en-US', {
                                            month: 'long',
                                            day: 'numeric',
                                            year: 'numeric',
                                        })}
                                    </td>
                                    <td className="p-3">{entry.quantity}</td>
                                    {canViewComputation && (
                                        <td className="p-3">
                                            {formatPeso(
                                                report.is_jo
                                                    ? report.daily_rate
                                                    : report.hourly_rate,
                                            )}
                                        </td>
                                    )}
                                    <td className="p-3 whitespace-pre-wrap">
                                        {entry.task_accomplished || '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {report.entries.length === 0 && (
                        <p className="p-6 text-sm text-muted-foreground">
                            No accomplishments added yet.
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}
