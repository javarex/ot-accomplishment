import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, FilePlus2, FileText, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { create, destroy, duplicate, edit, show } from '@/routes/reports';

type Report = {
    id: number;
    user_id: number;
    report_month: number;
    report_year: number;
    status: string;
    entries_count: number;
    user?: { name: string };
    prepared_name: string | null;
    prepared_by: { name: string } | null;
};
type PageData = { flash: { status?: string } };

function statusClass(status: string): string {
    if (status === 'generated')
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300';
    if (status === 'finalized')
        return 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300';
    return 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300';
}

export default function ReportsIndex({
    reports,
    currentUserId,
}: {
    currentUserId: number;
    reports: {
        data: Report[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
}) {
    const { flash } = usePage<PageData>().props;

    return (
        <>
            <Head title="Accomplishment reports" />
            <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 md:px-8 md:py-9">
                <div className="flex flex-wrap items-end justify-between gap-5">
                    <div>
                        <p className="text-xs font-semibold tracking-[0.18em] text-primary uppercase">
                            Report library
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Accomplishment reports
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Browse reports across the office. You can edit the
                            reports you own.
                        </p>
                    </div>
                    <Button asChild className="rounded-xl px-5">
                        <Link href={create()}>
                            <FilePlus2 className="size-4" /> New report
                        </Link>
                    </Button>
                </div>
                {flash?.status && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                        {flash.status}
                    </div>
                )}
                <section className="overflow-hidden rounded-2xl border bg-card shadow-sm shadow-black/2">
                    <div className="flex items-center justify-between border-b px-5 py-4 md:px-6">
                        <div>
                            <h2 className="font-semibold">All reports</h2>
                            <p className="text-xs text-muted-foreground">
                                Reporting period, owner, prepared by, and
                                current status
                            </p>
                        </div>
                        <FileText className="size-5 text-muted-foreground/60" />
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[700px] text-left text-sm">
                            <thead className="bg-muted/50 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-5 py-3 md:px-6">
                                        Reporting period
                                    </th>
                                    <th className="px-5 py-3">Owner</th>
                                    <th className="px-5 py-3">Prepared by</th>
                                    <th className="px-5 py-3">Entries</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3 text-right md:px-6">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {reports.data.map((report) => {
                                    const isOwner =
                                        report.user_id === currentUserId;
                                    return (
                                        <tr
                                            key={report.id}
                                            className="transition-colors hover:bg-muted/40"
                                        >
                                            <td className="px-5 py-4 font-medium md:px-6">
                                                {new Date(
                                                    report.report_year,
                                                    report.report_month - 1,
                                                    1,
                                                ).toLocaleDateString('en-US', {
                                                    month: 'long',
                                                    year: 'numeric',
                                                })}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {report.user?.name ?? 'Unknown'}{' '}
                                                {isOwner && (
                                                    <span className="ml-1 rounded-full bg-secondary px-2 py-0.5 text-[10px] font-semibold text-secondary-foreground">
                                                        You
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {report.prepared_name ||
                                                    report.prepared_by?.name ||
                                                    'Not set'}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground tabular-nums">
                                                {report.entries_count}
                                            </td>
                                            <td className="px-5 py-4">
                                                <span
                                                    className={`rounded-full px-2.5 py-1 text-xs font-medium capitalize ${statusClass(report.status)}`}
                                                >
                                                    {report.status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 md:px-6">
                                                <div className="flex items-center justify-end gap-4">
                                                    <Link
                                                        href={
                                                            isOwner
                                                                ? edit(
                                                                      report.id,
                                                                  )
                                                                : show(
                                                                      report.id,
                                                                  )
                                                        }
                                                        className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                                    >
                                                        {isOwner
                                                            ? 'Edit'
                                                            : 'View'}{' '}
                                                        <ArrowRight className="size-3.5" />
                                                    </Link>
                                                    {isOwner && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => {
                                                                if (
                                                                    window.confirm(
                                                                        'Duplicate this report as a new draft?',
                                                                    )
                                                                ) {
                                                                    router.post(
                                                                        duplicate(
                                                                            report.id,
                                                                        ).url,
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            Duplicate
                                                        </Button>
                                                    )}
                                                    {isOwner && (
                                                        <button
                                                            type="button"
                                                            aria-label={`Delete ${report.report_month}/${report.report_year} report`}
                                                            className="text-muted-foreground transition-colors hover:text-destructive"
                                                            onClick={() => {
                                                                if (
                                                                    confirm(
                                                                        'Delete this report and its entries?',
                                                                    )
                                                                )
                                                                    router.delete(
                                                                        destroy(
                                                                            report.id,
                                                                        ).url,
                                                                    );
                                                            }}
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {reports.data.length === 0 && (
                        <div className="flex flex-col items-center gap-3 px-6 py-14 text-center">
                            <div className="flex size-12 items-center justify-center rounded-2xl bg-secondary text-primary">
                                <FileText className="size-6" />
                            </div>
                            <p className="font-medium">No reports yet</p>
                            <p className="text-sm text-muted-foreground">
                                Create the first accomplishment report for your
                                office.
                            </p>
                            <Button asChild variant="outline" className="mt-1">
                                <Link href={create()}>Create report</Link>
                            </Button>
                        </div>
                    )}
                </section>
                {reports.links.length > 3 && (
                    <nav
                        aria-label="Report pages"
                        className="flex flex-wrap gap-2"
                    >
                        {reports.links.map((link, indexKey) =>
                            link.url ? (
                                <Link
                                    key={indexKey}
                                    href={link.url}
                                    className={`rounded-lg border px-3 py-1.5 text-sm transition-colors hover:bg-muted ${link.active ? 'border-primary bg-primary text-primary-foreground hover:bg-primary' : 'bg-card'}`}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ) : null,
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}
