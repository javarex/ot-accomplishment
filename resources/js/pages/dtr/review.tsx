import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { edit as editReport } from '@/routes/reports';
import { commit } from '@/routes/reports/dtr';

type DtrEntry = {
    id: number;
    work_date: string;
    am_in: string | null;
    am_out: string | null;
    pm_in: string | null;
    pm_out: string | null;
    overtime_minutes: number | null;
    remarks: string | null;
    selected_for_import: boolean;
};
type DtrImport = {
    id: number;
    employee_name: string;
    employee_id: string | null;
    month: number;
    year: number;
    original_filename: string;
    import_status: string;
    entries: DtrEntry[];
};
type PageData = { errors: Record<string, string>; flash: { status?: string } };

function quantity(minutes: number | null): string {
    if (!minutes) return 'No OT value';
    return `${Math.floor(minutes / 60)}h${minutes % 60 ? ` ${minutes % 60}m` : ''}`;
}

export default function DtrReview({
    report,
    import: dtrImport,
    existingDates,
}: {
    report: { id: number };
    import: DtrImport;
    existingDates: string[];
}) {
    const { errors, flash } = usePage<PageData>().props;
    const [selected, setSelected] = useState<number[]>(
        dtrImport.entries
            .filter((entry) => (entry.overtime_minutes ?? 0) > 0)
            .map((entry) => entry.id),
    );
    const [manualQuantities, setManualQuantities] = useState<
        Record<number, string>
    >({});
    const [duplicateAction, setDuplicateAction] = useState<'skip' | 'replace'>(
        'skip',
    );
    const [busy, setBusy] = useState(false);
    const dateSet = new Set(existingDates);
    const duplicateCount = dtrImport.entries.filter(
        (entry) =>
            selected.includes(entry.id) &&
            dateSet.has(entry.work_date.slice(0, 10)),
    ).length;

    function toggle(id: number) {
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    }

    function submit() {
        router.post(
            commit({ report: report.id, dtrImport: dtrImport.id }).url,
            {
                entry_ids: selected,
                duplicate_action: duplicateAction,
                manual_quantities: manualQuantities,
            },
            { onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
    }

    return (
        <>
            <Head title="Review imported DTR" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 md:px-8 md:py-9">
                <div>
                    <Link
                        href={editReport(report.id)}
                        className="text-sm underline"
                    >
                        Back to report
                    </Link>
                    <p className="mt-3 text-xs font-semibold tracking-[0.18em] text-primary uppercase">
                        DTR import
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                        Review imported DTR
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Review the extracted dates before creating
                        accomplishments.
                    </p>
                </div>
                {flash?.status && (
                    <div className="rounded-md border bg-muted p-3 text-sm">
                        {flash.status}
                    </div>
                )}
                {Object.keys(errors ?? {}).length > 0 && (
                    <div className="rounded-md border border-destructive p-3 text-sm text-destructive">
                        {Object.values(errors).map((message, index) => (
                            <div key={index}>{message}</div>
                        ))}
                    </div>
                )}
                <div className="grid gap-3 rounded-2xl border bg-card p-5 shadow-sm shadow-black/2 sm:grid-cols-2">
                    <div>
                        <span className="text-sm text-muted-foreground">
                            Employee
                        </span>
                        <p className="font-semibold">
                            {dtrImport.employee_name}
                        </p>
                        {dtrImport.employee_id && (
                            <p className="text-sm">
                                ID: {dtrImport.employee_id}
                            </p>
                        )}
                    </div>
                    <div>
                        <span className="text-sm text-muted-foreground">
                            Period
                        </span>
                        <p className="font-semibold">
                            {new Date(
                                dtrImport.year,
                                dtrImport.month - 1,
                                1,
                            ).toLocaleDateString('en-US', {
                                month: 'long',
                                year: 'numeric',
                            })}
                        </p>
                        <p className="text-sm">{dtrImport.original_filename}</p>
                    </div>
                </div>
                <section className="overflow-x-auto rounded-2xl border bg-card shadow-sm shadow-black/2">
                    <table className="w-full min-w-[700px] text-left text-sm">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="p-3">Import</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Time Rendered</th>
                                <th className="p-3">Remarks / Attendance</th>
                                <th className="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {dtrImport.entries.map((entry) => {
                                const duplicate = dateSet.has(
                                    entry.work_date.slice(0, 10),
                                );
                                return (
                                    <tr
                                        key={entry.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3">
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(
                                                    entry.id,
                                                )}
                                                onChange={() =>
                                                    toggle(entry.id)
                                                }
                                                aria-label={`Import ${entry.work_date}`}
                                            />
                                        </td>
                                        <td className="p-3">
                                            {new Date(
                                                `${entry.work_date.slice(0, 10)}T12:00:00`,
                                            ).toLocaleDateString('en-US', {
                                                month: 'long',
                                                day: 'numeric',
                                                year: 'numeric',
                                            })}
                                        </td>
                                        <td className="p-3">
                                            {quantity(entry.overtime_minutes)}
                                            {!entry.overtime_minutes &&
                                                selected.includes(entry.id) && (
                                                    <Input
                                                        className="mt-2 w-28"
                                                        placeholder="e.g. 3 documents"
                                                        value={
                                                            manualQuantities[
                                                                entry.id
                                                            ] ?? ''
                                                        }
                                                        onChange={(event) =>
                                                            setManualQuantities(
                                                                {
                                                                    ...manualQuantities,
                                                                    [entry.id]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                )}
                                        </td>
                                        <td className="p-3">
                                            {entry.remarks ||
                                                [
                                                    entry.am_in,
                                                    entry.am_out,
                                                    entry.pm_in,
                                                    entry.pm_out,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' / ') ||
                                                'No time record'}
                                        </td>
                                        <td className="p-3">
                                            {duplicate ? (
                                                <span className="text-amber-700 dark:text-amber-400">
                                                    Already in report
                                                </span>
                                            ) : entry.overtime_minutes ? (
                                                'Overtime'
                                            ) : (
                                                'Ordinary / no OT'
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </section>
                {duplicateCount > 0 && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950">
                        <strong>
                            {duplicateCount} selected date(s) already exist in
                            this report.
                        </strong>
                        <div className="mt-3 flex flex-wrap gap-4">
                            <label>
                                <input
                                    type="radio"
                                    checked={duplicateAction === 'skip'}
                                    onChange={() => setDuplicateAction('skip')}
                                />{' '}
                                Skip Existing (default)
                            </label>
                            <label>
                                <input
                                    type="radio"
                                    checked={duplicateAction === 'replace'}
                                    onChange={() =>
                                        setDuplicateAction('replace')
                                    }
                                />{' '}
                                Replace Existing quantities
                            </label>
                        </div>
                    </div>
                )}
                <p className="text-sm text-muted-foreground">
                    Only overtime rows are selected by default. To include an
                    ordinary day, select it and enter a quantity. Imported task
                    descriptions start blank.
                </p>
                <div className="flex gap-2">
                    <Button
                        disabled={busy || selected.length === 0}
                        onClick={submit}
                    >
                        Import Selected Records
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={editReport(report.id)}>Cancel Import</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
