import { formatPeso } from '@/lib/currency';
import { Head, Link, router, useHttp, usePage } from '@inertiajs/react';
import { GripVertical, Plus, Sparkles, Trash2 } from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type DragEvent,
    type FormEvent,
    type KeyboardEvent,
} from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    index as reportIndex,
    store as storeReport,
    update as updateReport,
    preview,
    generate,
    generateDocx,
} from '@/routes/reports';
import { improve } from '@/routes/reports/ai';
import {
    preview as previewDtr,
    store as uploadDtr,
    show as reviewDtr,
} from '@/routes/reports/dtr';
import {
    index as signatoryIndex,
    store as storeSignatory,
} from '@/routes/signatories';

type Signatory = {
    id: number;
    name: string;
    position: string;
    signatory_type: string;
};
type Entry = {
    key: string;
    id?: number;
    accomplishment_date: string;
    quantity: string;
    time_minutes: number | null;
    task_accomplished: string;
    original_task_accomplished: string;
    ai_suggested_task_accomplished: string;
    ai_enhanced: boolean;
};
type Report = {
    id: number;
    report_month: number;
    report_year: number;
    quantity_mode: 'custom' | 'time';
    hourly_rate: string | null;
    is_jo: boolean;
    daily_rate: string | null;
    jo_tax_percent: string;
    status: string;
    prepared_by_id: number | null;
    certified_by_id: number | null;
    approved_by_id: number | null;
    prepared_position: string | null;
    certified_position: string | null;
    approved_position: string | null;
    entries: Array<{
        id: number;
        accomplishment_date: string;
        quantity: string;
        time_minutes: number | null;
        task_accomplished: string | null;
        original_task_accomplished: string | null;
        ai_suggested_task_accomplished: string | null;
        ai_enhanced: boolean;
    }>;
    dtr_imports: Array<{
        id: number;
        original_filename: string;
        import_status: string;
    }>;
};
type PageData = {
    errors: Record<string, string>;
    flash: {
        status?: string;
        aiSuggestion?: { original: string; suggestion: string };
        createdSignatory?: { id: number; type: string };
    };
    csrfToken: string;
};
type DtrPreview = {
    employee_name: string;
    month: number;
    year: number;
    entries: Array<{
        date: string;
        am_in: string | null;
        am_out: string | null;
        pm_in: string | null;
        pm_out: string | null;
        overtime_minutes: number | null;
        remarks: string | null;
    }>;
};

let nextDraftEntryId = 0;

function createDraftEntryKey(): string {
    nextDraftEntryId += 1;
    return `draft-entry-${nextDraftEntryId}`;
}

function calculatedQuantity(date: string, minutes: number | null): string {
    if (!date || !minutes) return '';

    return `${Number((minutes / 60).toFixed(4))} hours`;
}

function durationLabel(minutes: number): string {
    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function importedMinutes(quantity: string): number | null {
    const match = quantity
        .trim()
        .match(
            /^(?:(\d+)\s*h(?:ours?|rs?)?)?\s*(?:(\d+)\s*m(?:in(?:utes?)?)?)?$/i,
        );
    if (!match || (!match[1] && !match[2])) return null;

    const minutes = Number(match[1] ?? 0) * 60 + Number(match[2] ?? 0);
    return minutes > 0 && minutes <= 59999 ? minutes : null;
}

export default function ReportEditor({
    report,
    signatories,
    canViewComputation,
    canEditComputation,
}: {
    report: Report | null;
    signatories: Record<string, Signatory[]>;
    canViewComputation: boolean;
    canEditComputation: boolean;
}) {
    const { errors, flash, csrfToken } = usePage<PageData>().props;
    const [month, setMonth] = useState(
        report?.report_month ?? new Date().getMonth() + 1,
    );
    const [year, setYear] = useState(
        report?.report_year ?? new Date().getFullYear(),
    );
    const [quantityMode, setQuantityMode] = useState<'custom' | 'time'>(
        report?.quantity_mode ?? 'time',
    );
    const [hourlyRate, setHourlyRate] = useState(report?.hourly_rate ?? '');
    const [isJo, setIsJo] = useState(report?.is_jo ?? false);
    const [dailyRate, setDailyRate] = useState(report?.daily_rate ?? '');
    const [joTaxPercent, setJoTaxPercent] = useState(
        report?.jo_tax_percent ?? '0',
    );
    const [prepared, setPrepared] = useState(report?.prepared_by_id ?? 0);
    const [certified, setCertified] = useState(report?.certified_by_id ?? 0);
    const [approved, setApproved] = useState(report?.approved_by_id ?? 0);
    const [overrides, setOverrides] = useState({
        prepared_position: report?.prepared_position ?? '',
        certified_position: report?.certified_position ?? '',
        approved_position: report?.approved_position ?? '',
    });
    const [entries, setEntries] = useState<Entry[]>(
        report?.entries.map((entry) => ({
            key: String(entry.id),
            id: entry.id,
            accomplishment_date: entry.accomplishment_date.slice(0, 10),
            quantity: entry.quantity,
            time_minutes: entry.time_minutes ?? importedMinutes(entry.quantity),
            task_accomplished: entry.task_accomplished ?? '',
            original_task_accomplished: entry.original_task_accomplished ?? '',
            ai_suggested_task_accomplished:
                entry.ai_suggested_task_accomplished ?? '',
            ai_enhanced: entry.ai_enhanced,
        })) ?? [],
    );
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [dtrFile, setDtrFile] = useState<File | null>(null);
    const dtrPreview = useHttp<{ dtr: File | null }, DtrPreview>({ dtr: null });
    const [dtrPreviewStatus, setDtrPreviewStatus] = useState<string | null>(
        null,
    );
    const [processedDtr, setProcessedDtr] = useState<DtrPreview | null>(null);
    const [showDtrRecords, setShowDtrRecords] = useState(false);
    const [dtrNeedsConfirmation, setDtrNeedsConfirmation] = useState(false);
    const [dtrView, setDtrView] = useState<'editable' | 'original'>('editable');
    const [dtrPdfUrl, setDtrPdfUrl] = useState<string | null>(null);
    useEffect(() => {
        if (!dtrFile) {
            setDtrPdfUrl(null);
            return;
        }
        const url = URL.createObjectURL(dtrFile);
        setDtrPdfUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [dtrFile]);
    const [otCellValues, setOtCellValues] = useState<Record<string, string>>(
        {},
    );
    const [otCellErrors, setOtCellErrors] = useState<Record<string, string>>(
        {},
    );
    const [dtrPreviewDates, setDtrPreviewDates] = useState<string[]>([]);
    const [aiIndex, setAiIndex] = useState<number | null>(null);
    const [suggestion, setSuggestion] = useState('');
    const [aiOriginal, setAiOriginal] = useState('');
    const draggedTaskKey = useRef<string | null>(null);
    const taskTextareas = useRef(new Map<string, HTMLTextAreaElement>());
    const [dropTargetKey, setDropTargetKey] = useState<string | null>(null);
    const [addSignatoryType, setAddSignatoryType] = useState<string | null>(
        null,
    );
    const [signatoryName, setSignatoryName] = useState('');
    const [signatoryPosition, setSignatoryPosition] = useState('');
    const [signatoryError, setSignatoryError] = useState('');
    const [savingSignatory, setSavingSignatory] = useState(false);

    function addSignatory(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!addSignatoryType) return;

        setSignatoryError('');
        router.post(
            storeSignatory().url,
            {
                _token: csrfToken,
                name: signatoryName,
                position: signatoryPosition,
                signatory_type: addSignatoryType,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSavingSignatory(true),
                onFinish: () => setSavingSignatory(false),
                onError: (validationErrors) =>
                    setSignatoryError(
                        validationErrors.name ||
                            validationErrors.position ||
                            validationErrors.signatory_type ||
                            'Could not save the signatory.',
                    ),
                onSuccess: (page) => {
                    const created = (page.props.flash as PageData['flash'])
                        ?.createdSignatory;
                    if (created?.type === 'prepared_by')
                        setPrepared(created.id);
                    if (created?.type === 'certified_correct')
                        setCertified(created.id);
                    if (created?.type === 'approved') setApproved(created.id);
                    setDirty(true);
                    setAddSignatoryType(null);
                    setSignatoryName('');
                    setSignatoryPosition('');
                },
            },
        );
    }

    function editEntry(index: number, patch: Partial<Entry>) {
        setEntries((current) =>
            current.map((entry, i) =>
                i === index ? { ...entry, ...patch } : entry,
            ),
        );
        setDirty(true);
    }

    function swapTasks(sourceKey: string, targetKey: string) {
        if (sourceKey === targetKey) return;
        setEntries((current) => {
            const source = current.find((entry) => entry.key === sourceKey);
            const target = current.find((entry) => entry.key === targetKey);
            if (!source || !target || !source.task_accomplished.trim())
                return current;

            return current.map((entry) => {
                if (entry.key !== sourceKey && entry.key !== targetKey)
                    return entry;
                const other = entry.key === sourceKey ? target : source;
                return {
                    ...entry,
                    task_accomplished: other.task_accomplished,
                    original_task_accomplished:
                        other.original_task_accomplished,
                    ai_suggested_task_accomplished:
                        other.ai_suggested_task_accomplished,
                    ai_enhanced: other.ai_enhanced,
                };
            });
        });
        setDirty(true);
    }

    function startTaskDrag(event: DragEvent<HTMLButtonElement>, key: string) {
        draggedTaskKey.current = key;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', key);
    }

    function dropTask(event: DragEvent<HTMLDivElement>, targetKey: string) {
        event.preventDefault();
        const sourceKey = draggedTaskKey.current;
        if (sourceKey) swapTasks(sourceKey, targetKey);
        draggedTaskKey.current = null;
        setDropTargetKey(null);
    }

    function navigateTasks(
        event: KeyboardEvent<HTMLTextAreaElement>,
        index: number,
    ) {
        if (
            event.key !== 'Tab' ||
            event.altKey ||
            event.ctrlKey ||
            event.metaKey
        )
            return;

        const next = entries[index + (event.shiftKey ? -1 : 1)];
        if (!next) return;

        const textarea = taskTextareas.current.get(next.key);
        if (!textarea) return;

        event.preventDefault();
        textarea.focus();
    }

    function save(finalize: boolean) {
        if (dtrNeedsConfirmation || Object.values(otCellErrors).some(Boolean)) {
            setShowDtrRecords(true);
            setDtrView('editable');
            return;
        }
        const previewedDtr = !report && dtrPreviewStatus ? dtrFile : null;
        const payload = {
            report_month: month,
            report_year: year,
            quantity_mode: quantityMode,
            is_jo: isJo,
            ...(canEditComputation
                ? {
                      hourly_rate: hourlyRate || null,
                      daily_rate: dailyRate || null,
                      jo_tax_percent: joTaxPercent || '0',
                  }
                : {}),
            prepared_by_id: prepared,
            certified_by_id: certified,
            approved_by_id: approved,
            ...overrides,
            prepared_name: '',
            certified_name: '',
            approved_name: '',
            entries: entries.map(({ key: _key, ...entry }) => entry),
            finalize,
            ...(previewedDtr
                ? {
                      dtr: previewedDtr,
                      dtr_previewed: true,
                      dtr_rows:
                          processedDtr?.entries.map(
                              ({
                                  date,
                                  am_in,
                                  am_out,
                                  pm_in,
                                  pm_out,
                                  overtime_minutes,
                                  remarks,
                              }) => ({
                                  date,
                                  am_in,
                                  am_out,
                                  pm_in,
                                  pm_out,
                                  overtime_minutes,
                                  remarks,
                              }),
                          ) ?? [],
                      dtr_import_dates: entries
                          .map((entry) => entry.accomplishment_date)
                          .filter((date) => dtrPreviewDates.includes(date)),
                  }
                : {}),
        };
        const options = {
            preserveScroll: true,
            preserveState: Boolean(previewedDtr),
            forceFormData: Boolean(previewedDtr),
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        };
        if (report) router.put(updateReport(report.id).url, payload, options);
        else router.post(storeReport().url, payload, options);
    }

    function askAi(index: number) {
        if (!report || !entries[index].task_accomplished.trim()) return;
        setAiIndex(index);
        setAiOriginal(entries[index].task_accomplished);
        setSuggestion('');
        requestSuggestion(entries[index].task_accomplished);
    }

    function requestSuggestion(text: string) {
        if (!report) return;
        router.post(
            improve(report.id).url,
            { text },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: (page) => {
                    const value = (page.props.flash as PageData['flash'])
                        ?.aiSuggestion;
                    if (value) setSuggestion(value.suggestion);
                },
            },
        );
    }

    function acceptSuggestion() {
        if (aiIndex === null || !suggestion) return;
        editEntry(aiIndex, {
            task_accomplished: suggestion,
            original_task_accomplished:
                entries[aiIndex].original_task_accomplished || aiOriginal,
            ai_suggested_task_accomplished: suggestion,
            ai_enhanced: true,
        });
        setAiIndex(null);
    }

    function editDtrAttendance(
        date: string,
        field: 'am_in' | 'am_out' | 'pm_in' | 'pm_out' | 'remarks',
        value: string,
    ) {
        setProcessedDtr((current) =>
            current
                ? {
                      ...current,
                      entries: current.entries.map((entry) =>
                          entry.date === date
                              ? { ...entry, [field]: value || null }
                              : entry,
                      ),
                  }
                : current,
        );
        setDtrNeedsConfirmation(true);
    }

    function editOtCell(date: string, value: string) {
        setDtrNeedsConfirmation(true);
        setOtCellValues((current) => ({ ...current, [date]: value }));
        const minutes = importedMinutes(value);
        const source = processedDtr?.entries.find(
            (entry) => entry.date === date,
        );
        const emptyOtCell = !value.trim();
        setOtCellErrors((current) => ({
            ...current,
            [date]:
                minutes || emptyOtCell
                    ? ''
                    : 'Enter OT time, e.g. 2h 15m (maximum 999h 59m).',
        }));
        if ((!minutes && !emptyOtCell) || !source) return;
        setProcessedDtr((current) =>
            current
                ? {
                      ...current,
                      entries: current.entries.map((entry) =>
                          entry.date === date
                              ? { ...entry, overtime_minutes: minutes }
                              : entry,
                      ),
                  }
                : current,
        );
        setDtrNeedsConfirmation(true);
    }

    function confirmDtrOvertime() {
        if (!processedDtr || Object.values(otCellErrors).some(Boolean)) return;
        const overtimeEntries = processedDtr.entries.filter(
            (entry) => (entry.overtime_minutes ?? 0) > 0,
        );
        const clearedDates = new Set(
            processedDtr.entries
                .filter(
                    (entry) =>
                        !entry.overtime_minutes &&
                        dtrPreviewDates.includes(entry.date),
                )
                .map((entry) => entry.date),
        );
        setEntries((current) => {
            const retained = current.filter(
                (entry) => !clearedDates.has(entry.accomplishment_date),
            );
            const updated = retained.map((entry) => {
                const dtrEntry = overtimeEntries.find(
                    (row) => row.date === entry.accomplishment_date,
                );
                if (!dtrEntry) return entry;
                return {
                    ...entry,
                    time_minutes: dtrEntry.overtime_minutes,
                    quantity:
                        quantityMode === 'time'
                            ? calculatedQuantity(
                                  dtrEntry.date,
                                  dtrEntry.overtime_minutes,
                              )
                            : durationLabel(dtrEntry.overtime_minutes!),
                };
            });
            const existingDates = new Set(
                current.map((entry) => entry.accomplishment_date),
            );
            const added = overtimeEntries
                .filter((entry) => !existingDates.has(entry.date))
                .map((entry): Entry => ({
                    key: createDraftEntryKey(),
                    accomplishment_date: entry.date,
                    quantity:
                        quantityMode === 'time'
                            ? calculatedQuantity(
                                  entry.date,
                                  entry.overtime_minutes,
                              )
                            : durationLabel(entry.overtime_minutes!),
                    time_minutes: entry.overtime_minutes,
                    task_accomplished: '',
                    original_task_accomplished: '',
                    ai_suggested_task_accomplished: '',
                    ai_enhanced: false,
                }));
            return [...updated, ...added];
        });
        setDtrPreviewDates(overtimeEntries.map((entry) => entry.date));
        setMonth(processedDtr.month);
        setYear(processedDtr.year);
        setDtrNeedsConfirmation(false);
        setDirty(true);
        setDtrPreviewStatus(
            `${overtimeEntries.length} confirmed OT date(s) applied to 03 · Write. ${clearedDates.size} cleared OT date(s) removed. Save the report to keep these changes.`,
        );
        setShowDtrRecords(false);
    }

    async function importDtr(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!dtrFile) return;
        if (!report) {
            try {
                const preview = await dtrPreview.post(previewDtr().url);
                setOtCellValues({});
                setOtCellErrors({});
                setProcessedDtr(preview);
                setDtrView('editable');
                setShowDtrRecords(true);
                setDtrNeedsConfirmation(true);
                setDtrPreviewStatus(
                    `Read ${preview.employee_name}'s DTR. Review the OT cells and confirm in the modal to apply them to 03 · Write.`,
                );
            } catch {
                setDtrPreviewStatus(null);
            }
            return;
        }
        router.post(
            uploadDtr(report.id).url,
            { dtr: dtrFile },
            {
                forceFormData: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    }

    const dtrOvertimeMinutes = (processedDtr?.entries ?? []).reduce(
        (total, entry) =>
            total +
            (otCellValues[entry.date] === undefined
                ? (entry.overtime_minutes ?? 0)
                : (importedMinutes(otCellValues[entry.date]) ?? 0)),
        0,
    );
    const hasInvalidDtrOt = Object.values(otCellErrors).some(Boolean);

    const overtimeTotals = entries.reduce(
        (totals, entry) => {
            if (!entry.accomplishment_date || !entry.time_minutes)
                return totals;
            const day = new Date(
                `${entry.accomplishment_date}T00:00:00Z`,
            ).getUTCDay();
            const group =
                day === 0 || day === 6 ? totals.weekend : totals.weekday;
            group.hours += Math.floor(entry.time_minutes / 60);
            group.minutes += entry.time_minutes % 60;
            if (!isJo && hourlyRate !== '') {
                const rateCents = Math.round(Number(hourlyRate) * 100);
                group.payNumerator +=
                    entry.time_minutes *
                    rateCents *
                    (day === 0 || day === 6 ? 150 : 125);
            }
            return totals;
        },
        {
            weekday: { hours: 0, minutes: 0, payNumerator: 0 },
            weekend: { hours: 0, minutes: 0, payNumerator: 0 },
        },
    );
    const weekdayMinutes =
        overtimeTotals.weekday.hours * 60 + overtimeTotals.weekday.minutes;
    const weekendMinutes =
        overtimeTotals.weekend.hours * 60 + overtimeTotals.weekend.minutes;
    const weekdayPay =
        Math.round(overtimeTotals.weekday.payNumerator / 6000) / 100;
    const weekendPay =
        Math.round(overtimeTotals.weekend.payNumerator / 6000) / 100;
    const payComplete =
        (isJo ? dailyRate !== '' : hourlyRate !== '') &&
        entries.every((entry) => !!entry.time_minutes);
    const grossCents = isJo
        ? Math.round(
              (Math.round(Number(dailyRate) * 100) *
                  (weekdayMinutes + weekendMinutes)) /
                  (8 * 60),
          )
        : Math.round(weekdayPay * 100) + Math.round(weekendPay * 100);
    const deductionCents = isJo
        ? Math.round(
              (grossCents * Math.round(Number(joTaxPercent) * 100)) / 10000,
          )
        : Math.round(grossCents * 0.2);
    const netPay = (grossCents - deductionCents) / 100;

    const canGeneratePdf =
        report &&
        !dirty &&
        (quantityMode !== 'time' ||
            !isJo ||
            !canViewComputation ||
            payComplete) &&
        entries.length > 0 &&
        entries.every(
            (entry) =>
                entry.task_accomplished.trim() &&
                (quantityMode === 'time'
                    ? calculatedQuantity(
                          entry.accomplishment_date,
                          entry.time_minutes,
                      )
                    : entry.quantity.trim()),
        );
    const canGenerate =
        canGeneratePdf &&
        (quantityMode !== 'time' || !canViewComputation || payComplete);

    return (
        <>
            <Head
                title={
                    report
                        ? 'Edit accomplishment report'
                        : 'Create accomplishment report'
                }
            />
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 md:px-8 md:py-9">
                <div className="flex flex-wrap items-end justify-between gap-4 rounded-2xl border bg-card px-5 py-5 shadow-sm shadow-black/2 md:px-7">
                    <div>
                        <Link
                            href={reportIndex()}
                            className="text-xs font-semibold tracking-[0.16em] text-primary uppercase hover:underline"
                        >
                            ← All reports
                        </Link>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            {report
                                ? 'Accomplishment Report'
                                : 'Create Accomplishment Report'}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {report
                                ? `Status: ${report.status}`
                                : 'Start with a reporting period and signatories.'}
                        </p>
                    </div>
                    {report && (
                        <Link
                            href={preview(report.id)}
                            target="_blank"
                            className="rounded-xl border bg-background px-4 py-2 text-sm font-medium transition-colors hover:bg-muted"
                        >
                            Preview report
                        </Link>
                    )}
                </div>
                {flash?.status && (
                    <div className="rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-900 dark:bg-green-950 dark:text-green-100">
                        {flash.status}
                    </div>
                )}
                {Object.keys(errors ?? {}).length > 0 && (
                    <div className="rounded-md border border-destructive p-3 text-sm text-destructive">
                        {Object.values(errors).map((error, index) => (
                            <div key={index}>{error}</div>
                        ))}
                    </div>
                )}
                <section className="grid gap-5 rounded-2xl border bg-card p-5 shadow-sm shadow-black/2 md:grid-cols-2 md:p-6">
                    <div className="md:col-span-2">
                        <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                            01 · Setup
                        </p>
                        <h2 className="mt-1 text-lg font-semibold">
                            Report details
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Choose the reporting period and who will sign the
                            report. When processing a DTR on a new report, its
                            month and year set the reporting period.
                        </p>
                    </div>
                    <div>
                        <Label htmlFor="period">Reporting period</Label>
                        <Input
                            id="period"
                            type="month"
                            disabled={!report && dtrFile !== null}
                            value={`${year}-${String(month).padStart(2, '0')}`}
                            onChange={(event) => {
                                const [y, m] = event.target.value
                                    .split('-')
                                    .map(Number);
                                setYear(y);
                                setMonth(m);
                                setDirty(true);
                            }}
                        />
                    </div>
                    <div className="flex items-end">
                        <Link
                            href={signatoryIndex()}
                            className="text-sm underline"
                        >
                            Manage saved signatories
                        </Link>
                    </div>
                    {(
                        [
                            [
                                'prepared',
                                'Prepared By',
                                prepared,
                                setPrepared,
                                'prepared_by',
                            ],
                            [
                                'certified',
                                'Certified Correct',
                                certified,
                                setCertified,
                                'certified_correct',
                            ],
                            [
                                'approved',
                                'Approved By',
                                approved,
                                setApproved,
                                'approved',
                            ],
                        ] as const
                    ).map(([key, label, value, setter, type]) => (
                        <div
                            key={key}
                            className="grid gap-2 rounded-xl border bg-muted/30 p-4"
                        >
                            <Label htmlFor={`${key}-signatory`}>{label}</Label>
                            <div className="flex gap-2">
                                <select
                                    id={`${key}-signatory`}
                                    className="h-9 min-w-0 flex-1 rounded-md border bg-background px-2 text-sm"
                                    value={value}
                                    onChange={(event) => {
                                        setter(Number(event.target.value));
                                        setDirty(true);
                                    }}
                                >
                                    <option value={0}>
                                        Select a signatory
                                    </option>
                                    {(signatories[type] ?? []).map((person) => (
                                        <option
                                            key={person.id}
                                            value={person.id}
                                        >
                                            {person.name} — {person.position}
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label={`Add ${label} signatory`}
                                    title={`Add ${label} signatory`}
                                    onClick={() => {
                                        setSignatoryName('');
                                        setSignatoryPosition('');
                                        setSignatoryError('');
                                        setAddSignatoryType(type);
                                    }}
                                >
                                    <Plus />
                                </Button>
                            </div>
                            <Input
                                placeholder="Override position (optional)"
                                value={
                                    overrides[
                                        `${key}_position` as keyof typeof overrides
                                    ]
                                }
                                onChange={(event) => {
                                    setOverrides({
                                        ...overrides,
                                        [`${key}_position`]: event.target.value,
                                    });
                                    setDirty(true);
                                }}
                            />
                        </div>
                    ))}
                </section>
                <section className="rounded-2xl border bg-card p-5 shadow-sm shadow-black/2 md:p-6">
                    <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                        02 · Import
                    </p>
                    <h2 className="mt-1 text-lg font-semibold">
                        Daily Time Record
                    </h2>
                    <p className="mb-3 text-sm text-muted-foreground">
                        Upload a text-based Civil Service Form No. 48 PDF.
                        {report
                            ? ' You will review detected dates before importing them.'
                            : ' Review and edit the DTR in the modal, then confirm to apply OT dates to the report.'}
                    </p>
                    <form
                        onSubmit={importDtr}
                        className="flex flex-wrap items-end gap-3"
                    >
                        <div>
                            <Label htmlFor="dtr">DTR PDF</Label>
                            <Input
                                id="dtr"
                                type="file"
                                accept="application/pdf,.pdf"
                                onChange={(event) => {
                                    const file =
                                        event.target.files?.[0] ?? null;
                                    setDtrFile(file);
                                    dtrPreview.setData('dtr', file);
                                    dtrPreview.clearErrors();
                                    setOtCellValues({});
                                    setOtCellErrors({});
                                    setDtrNeedsConfirmation(false);
                                    setProcessedDtr(null);
                                    setShowDtrRecords(false);
                                    setDtrPreviewStatus(null);
                                    setDtrPreviewDates([]);
                                }}
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={!dtrFile || busy || dtrPreview.processing}
                        >
                            Process DTR
                        </Button>
                    </form>
                    {!report && processedDtr && (
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3"
                            onClick={() => setShowDtrRecords(true)}
                        >
                            View All DTR Records
                        </Button>
                    )}
                    <Dialog
                        open={showDtrRecords}
                        onOpenChange={setShowDtrRecords}
                    >
                        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-5xl">
                            <DialogHeader>
                                <DialogTitle>Uploaded DTR Records</DialogTitle>
                                <DialogDescription>
                                    {processedDtr?.employee_name} ·{' '}
                                    {processedDtr &&
                                        new Date(
                                            processedDtr.year,
                                            processedDtr.month - 1,
                                            1,
                                        ).toLocaleDateString('en-US', {
                                            month: 'long',
                                            year: 'numeric',
                                        })}{' '}
                                    · {processedDtr?.entries.length ?? 0}{' '}
                                    records. All attendance dates are shown,
                                    including dates without OT.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="flex gap-2" aria-label="DTR view">
                                <Button
                                    type="button"
                                    variant={
                                        dtrView === 'editable'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setDtrView('editable')}
                                >
                                    Editable DTR
                                </Button>
                                <Button
                                    type="button"
                                    variant={
                                        dtrView === 'original'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setDtrView('original')}
                                >
                                    Original PDF
                                </Button>
                            </div>
                            {dtrView === 'original' && dtrPdfUrl ? (
                                <iframe
                                    src={dtrPdfUrl}
                                    title="Original uploaded DTR"
                                    className="h-[65vh] w-full rounded-md border"
                                />
                            ) : (
                                <div className="overflow-x-auto rounded-md bg-muted p-3 md:p-5">
                                    <div className="mx-auto max-w-4xl min-w-[760px] border border-black bg-white p-6 font-serif text-black shadow-md">
                                        <p className="text-xs italic">
                                            Civil Service Form No. 48
                                        </p>
                                        <h2 className="mt-3 text-center text-xl font-bold tracking-widest">
                                            DAILY TIME RECORD
                                        </h2>
                                        <p className="mt-4 border-b border-black text-center font-semibold">
                                            {processedDtr?.employee_name}
                                        </p>
                                        <p className="text-center text-xs">
                                            (Name)
                                        </p>
                                        <p className="my-4 text-center text-sm">
                                            For the month of{' '}
                                            <span className="font-semibold underline">
                                                {processedDtr &&
                                                    new Date(
                                                        processedDtr.year,
                                                        processedDtr.month - 1,
                                                        1,
                                                    ).toLocaleDateString(
                                                        'en-US',
                                                        {
                                                            month: 'long',
                                                            year: 'numeric',
                                                        },
                                                    )}
                                            </span>
                                        </p>
                                        <table className="w-full border-collapse text-center text-sm [&_td]:border [&_td]:border-black [&_td]:p-1 [&_th]:border [&_th]:border-black [&_th]:p-2">
                                            <thead>
                                                <tr>
                                                    <th rowSpan={2}>Day</th>
                                                    <th colSpan={2}>A.M.</th>
                                                    <th colSpan={2}>P.M.</th>
                                                    <th rowSpan={2}>
                                                        OT rendered
                                                    </th>
                                                    <th rowSpan={2}>Remarks</th>
                                                </tr>
                                                <tr>
                                                    <th>Arrival</th>
                                                    <th>Departure</th>
                                                    <th>Arrival</th>
                                                    <th>Departure</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {processedDtr?.entries.map(
                                                    (entry) => (
                                                        <tr key={entry.date}>
                                                            <td
                                                                className="w-10"
                                                                title={
                                                                    entry.date
                                                                }
                                                            >
                                                                {Number(
                                                                    entry.date.slice(
                                                                        -2,
                                                                    ),
                                                                )}
                                                            </td>
                                                            {(
                                                                [
                                                                    'am_in',
                                                                    'am_out',
                                                                    'pm_in',
                                                                    'pm_out',
                                                                ] as const
                                                            ).map((field) => (
                                                                <td key={field}>
                                                                    <input
                                                                        className="h-8 w-20 bg-white text-center text-black outline-none focus:bg-blue-50 focus:ring-2 focus:ring-blue-600"
                                                                        value={
                                                                            entry[
                                                                                field
                                                                            ] ??
                                                                            ''
                                                                        }
                                                                        placeholder=""
                                                                        maxLength={
                                                                            5
                                                                        }
                                                                        aria-label={`${field.replace('_', ' ')} for ${entry.date}`}
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            editDtrAttendance(
                                                                                entry.date,
                                                                                field,
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                    />
                                                                </td>
                                                            ))}
                                                            <td className="w-28">
                                                                <input
                                                                    className="h-8 w-28 bg-white text-center text-black outline-none focus:bg-blue-50 focus:ring-2 focus:ring-blue-600"
                                                                    aria-label={`OT rendered time for ${entry.date}`}
                                                                    placeholder="e.g. 2h 15m"
                                                                    value={
                                                                        otCellValues[
                                                                            entry
                                                                                .date
                                                                        ] ??
                                                                        (entry.overtime_minutes
                                                                            ? durationLabel(
                                                                                  entry.overtime_minutes,
                                                                              )
                                                                            : '')
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        editOtCell(
                                                                            entry.date,
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    aria-invalid={Boolean(
                                                                        otCellErrors[
                                                                            entry
                                                                                .date
                                                                        ],
                                                                    )}
                                                                    aria-describedby={
                                                                        otCellErrors[
                                                                            entry
                                                                                .date
                                                                        ]
                                                                            ? `ot-error-${entry.date}`
                                                                            : undefined
                                                                    }
                                                                />
                                                                {otCellErrors[
                                                                    entry.date
                                                                ] && (
                                                                    <p
                                                                        id={`ot-error-${entry.date}`}
                                                                        role="alert"
                                                                        className="mt-1 max-w-28 text-xs text-red-700"
                                                                    >
                                                                        {
                                                                            otCellErrors[
                                                                                entry
                                                                                    .date
                                                                            ]
                                                                        }
                                                                    </p>
                                                                )}
                                                            </td>
                                                            <td>
                                                                <input
                                                                    className="h-8 w-full min-w-28 bg-white px-1 text-black outline-none focus:bg-blue-50 focus:ring-2 focus:ring-blue-600"
                                                                    value={
                                                                        entry.remarks ??
                                                                        ''
                                                                    }
                                                                    maxLength={
                                                                        1000
                                                                    }
                                                                    aria-label={`Remarks for ${entry.date}`}
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        editDtrAttendance(
                                                                            entry.date,
                                                                            'remarks',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                />
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                            <tfoot>
                                                <tr className="font-bold">
                                                    <th
                                                        colSpan={5}
                                                        scope="row"
                                                        className="text-right"
                                                    >
                                                        Total OT rendered
                                                    </th>
                                                    <td
                                                        className="whitespace-nowrap"
                                                        aria-live="polite"
                                                    >
                                                        {durationLabel(
                                                            dtrOvertimeMinutes,
                                                        )}
                                                    </td>
                                                    <td className="text-xs font-normal">
                                                        {hasInvalidDtrOt
                                                            ? 'Correct invalid OT cells to complete the total.'
                                                            : ''}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            )}
                            <p className="text-sm text-muted-foreground">
                                Edit attendance times using HH:MM and type
                                remarks directly in the cells. Enter OT rendered
                                time directly in its cell, e.g. 2h 15m. Clear an
                                OT cell to remove its confirmed date from 03 ·
                                Write. Changes are applied to 03 · Write only
                                after confirmation.
                            </p>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    disabled={hasInvalidDtrOt}
                                    onClick={confirmDtrOvertime}
                                >
                                    Confirm OT and Apply to Write
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => setShowDtrRecords(false)}
                                >
                                    Close
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                    {!report && dtrPreviewStatus && (
                        <p className="mt-3 rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-900 dark:bg-green-950 dark:text-green-100">
                            {dtrPreviewStatus}
                        </p>
                    )}
                    {!report && dtrPreview.errors.dtr && (
                        <p className="mt-3 text-sm text-destructive">
                            {dtrPreview.errors.dtr}
                        </p>
                    )}
                    {report && report.dtr_imports?.length > 0 && (
                        <div className="mt-4 space-y-2">
                            {report.dtr_imports.map((item) => (
                                <div key={item.id} className="text-sm">
                                    <Link
                                        href={reviewDtr({
                                            report: report.id,
                                            dtrImport: item.id,
                                        })}
                                        className="underline"
                                    >
                                        {item.original_filename}
                                    </Link>{' '}
                                    <span className="text-muted-foreground">
                                        ({item.import_status})
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
                <section className="rounded-2xl border bg-card p-5 shadow-sm shadow-black/2 md:p-6">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                                03 · Write
                            </p>
                            <h2 className="text-lg font-semibold">
                                Accomplishments
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Drag a task onto another task to place it on the
                                correct date. Filled tasks swap places.
                            </p>
                        </div>
                        <div className="w-full sm:w-44">
                            <Label htmlFor="report-quantity-mode">
                                Quantity type for all
                            </Label>
                            <select
                                id="report-quantity-mode"
                                className="mt-1 h-9 w-full rounded-md border bg-background px-2 text-sm"
                                value={quantityMode}
                                onChange={(event) => {
                                    const nextMode = event.target.value as
                                        | 'custom'
                                        | 'time';
                                    if (nextMode === 'time') {
                                        setEntries((current) =>
                                            current.map((entry) => ({
                                                ...entry,
                                                time_minutes:
                                                    entry.time_minutes ??
                                                    importedMinutes(
                                                        entry.quantity,
                                                    ),
                                            })),
                                        );
                                    }
                                    setQuantityMode(nextMode);
                                    setDirty(true);
                                }}
                            >
                                <option value="time">Time based</option>
                                <option value="custom">Custom text</option>
                            </select>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                type="button"
                                onClick={() => {
                                    setEntries(
                                        [...entries].sort((a, b) =>
                                            a.accomplishment_date.localeCompare(
                                                b.accomplishment_date,
                                            ),
                                        ),
                                    );
                                    setDirty(true);
                                }}
                            >
                                Sort by date
                            </Button>
                            <Button
                                variant="outline"
                                type="button"
                                onClick={() => {
                                    setEntries([
                                        ...entries,
                                        {
                                            key: createDraftEntryKey(),
                                            accomplishment_date: '',
                                            quantity: '',
                                            time_minutes: null,
                                            task_accomplished: '',
                                            original_task_accomplished: '',
                                            ai_suggested_task_accomplished: '',
                                            ai_enhanced: false,
                                        },
                                    ]);
                                    setDirty(true);
                                }}
                            >
                                <Plus /> Add
                            </Button>
                        </div>
                    </div>
                    {quantityMode === 'time' && (
                        <label className="mb-4 flex items-center gap-2 text-sm font-medium">
                            <input
                                type="checkbox"
                                checked={isJo}
                                onChange={(event) => {
                                    setIsJo(event.target.checked);
                                    setDirty(true);
                                }}
                            />
                            JO (Job Order) · fixed 100% for every day
                        </label>
                    )}
                    {quantityMode === 'time' && canViewComputation && (
                        <div className="mb-4 flex flex-wrap gap-3">
                            <div className="w-full max-w-xs">
                                <Label htmlFor="report-rate">
                                    {isJo
                                        ? 'Daily rate for JO report'
                                        : 'Hourly rate for all records'}
                                </Label>
                                <Input
                                    id="report-rate"
                                    type="number"
                                    min="0"
                                    max="99999999.99"
                                    step="0.01"
                                    value={isJo ? dailyRate : hourlyRate}
                                    placeholder={
                                        isJo
                                            ? 'Enter daily rate'
                                            : 'Enter hourly rate'
                                    }
                                    readOnly={!canEditComputation}
                                    onChange={(event) => {
                                        if (isJo)
                                            setDailyRate(event.target.value);
                                        else setHourlyRate(event.target.value);
                                        setDirty(true);
                                    }}
                                />
                            </div>
                            {isJo && (
                                <div className="w-full max-w-xs">
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
                                        readOnly={!canEditComputation}
                                        onChange={(event) => {
                                            setJoTaxPercent(event.target.value);
                                            setDirty(true);
                                        }}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                    <div className="space-y-3">
                        {entries.length === 0 && (
                            <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                                No entries yet. Add one manually or import a
                                DTR.
                            </p>
                        )}
                        {entries.map((entry, index) => (
                            <div
                                key={entry.key}
                                className="grid gap-3 rounded-lg border p-3 md:grid-cols-[150px_290px_1fr_auto]"
                            >
                                <div>
                                    <Label>Date</Label>
                                    <Input
                                        type="date"
                                        value={entry.accomplishment_date}
                                        onChange={(event) =>
                                            editEntry(index, {
                                                accomplishment_date:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Quantity</Label>
                                    {quantityMode === 'time' ? (
                                        <>
                                            <div className="flex gap-2">
                                                <Input
                                                    aria-label="Hours"
                                                    type="number"
                                                    min="0"
                                                    max="999"
                                                    value={Math.floor(
                                                        (entry.time_minutes ??
                                                            0) / 60,
                                                    )}
                                                    onChange={(event) =>
                                                        editEntry(index, {
                                                            time_minutes:
                                                                Number(
                                                                    event.target
                                                                        .value,
                                                                ) *
                                                                    60 +
                                                                ((entry.time_minutes ??
                                                                    0) %
                                                                    60),
                                                        })
                                                    }
                                                />
                                                <Input
                                                    aria-label="Minutes"
                                                    type="number"
                                                    min="0"
                                                    max="59"
                                                    value={
                                                        (entry.time_minutes ??
                                                            0) % 60
                                                    }
                                                    onChange={(event) =>
                                                        editEntry(index, {
                                                            time_minutes:
                                                                Math.floor(
                                                                    (entry.time_minutes ??
                                                                        0) / 60,
                                                                ) *
                                                                    60 +
                                                                Number(
                                                                    event.target
                                                                        .value,
                                                                ),
                                                        })
                                                    }
                                                />
                                            </div>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Hours + minutes ·{' '}
                                                {calculatedQuantity(
                                                    entry.accomplishment_date,
                                                    entry.time_minutes,
                                                ) ||
                                                    'Select a date and enter time'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {isJo
                                                    ? 'JO: daily rate ÷ 8 × rendered hours · 100% every day'
                                                    : 'Weekdays: hourly rate × 125% · Weekends: hourly rate × 150%'}
                                            </p>
                                        </>
                                    ) : (
                                        <Input
                                            aria-label="Quantity"
                                            value={entry.quantity}
                                            onChange={(event) =>
                                                editEntry(index, {
                                                    quantity:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="e.g. 3 documents"
                                        />
                                    )}
                                </div>
                                <div>
                                    <div
                                        className={
                                            dropTargetKey === entry.key
                                                ? 'rounded-md ring-2 ring-primary'
                                                : ''
                                        }
                                        onDragOver={(event) => {
                                            if (
                                                draggedTaskKey.current &&
                                                draggedTaskKey.current !==
                                                    entry.key
                                            ) {
                                                event.preventDefault();
                                                event.dataTransfer.dropEffect =
                                                    'move';
                                                setDropTargetKey(entry.key);
                                            }
                                        }}
                                        onDragLeave={() =>
                                            setDropTargetKey(null)
                                        }
                                        onDrop={(event) =>
                                            dropTask(event, entry.key)
                                        }
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <Label>Task Accomplished</Label>
                                            <button
                                                type="button"
                                                draggable={Boolean(
                                                    entry.task_accomplished.trim(),
                                                )}
                                                onDragStart={(event) =>
                                                    startTaskDrag(
                                                        event,
                                                        entry.key,
                                                    )
                                                }
                                                onDragEnd={() => {
                                                    draggedTaskKey.current =
                                                        null;
                                                    setDropTargetKey(null);
                                                }}
                                                className="flex cursor-grab items-center gap-1 rounded px-2 py-1 text-xs text-muted-foreground hover:bg-muted active:cursor-grabbing disabled:cursor-not-allowed"
                                                disabled={
                                                    !entry.task_accomplished.trim()
                                                }
                                                aria-label={`Drag task from ${entry.accomplishment_date || `entry ${index + 1}`}`}
                                                title="Drag this task to another date"
                                            >
                                                <GripVertical className="size-4" />{' '}
                                                Drag task
                                            </button>
                                        </div>
                                        <textarea
                                            ref={(element) => {
                                                if (element)
                                                    taskTextareas.current.set(
                                                        entry.key,
                                                        element,
                                                    );
                                                else
                                                    taskTextareas.current.delete(
                                                        entry.key,
                                                    );
                                            }}
                                            className="min-h-20 w-full rounded-md border bg-background p-2 text-sm"
                                            value={entry.task_accomplished}
                                            onKeyDown={(event) =>
                                                navigateTasks(event, index)
                                            }
                                            onChange={(event) =>
                                                editEntry(index, {
                                                    task_accomplished:
                                                        event.target.value,
                                                    ai_enhanced: false,
                                                })
                                            }
                                            placeholder="Describe the work completed"
                                        />
                                    </div>
                                    <div className="mt-1 flex gap-2">
                                        {report && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => askAi(index)}
                                                disabled={
                                                    !entry.task_accomplished.trim() ||
                                                    busy
                                                }
                                            >
                                                <Sparkles /> Improve with AI
                                            </Button>
                                        )}
                                        {entry.ai_enhanced && (
                                            <span className="text-xs text-muted-foreground">
                                                AI enhanced · original retained
                                            </span>
                                        )}
                                        {entry.original_task_accomplished &&
                                            entry.task_accomplished !==
                                                entry.original_task_accomplished && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        editEntry(index, {
                                                            task_accomplished:
                                                                entry.original_task_accomplished,
                                                            ai_enhanced: false,
                                                        })
                                                    }
                                                >
                                                    Restore original
                                                </Button>
                                            )}
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-1 md:flex-col">
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        title="Delete entry"
                                        onClick={() => {
                                            setEntries(
                                                entries.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            );
                                            setDirty(true);
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                    {quantityMode === 'time' && canViewComputation && (
                        <div className="mt-4 grid gap-3 sm:grid-cols-3">
                            {!isJo && (
                                <>
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Weekdays · OT Hour (25%) · ×125%
                                        </p>
                                        <p className="text-lg font-semibold">
                                            {durationLabel(weekdayMinutes)}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Hours:{' '}
                                            {overtimeTotals.weekday.hours} ·
                                            Minutes:{' '}
                                            {overtimeTotals.weekday.minutes}
                                        </p>
                                        <p>
                                            Gross OT pay:{' '}
                                            {payComplete
                                                ? formatPeso(weekdayPay)
                                                : '—'}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Weekends · OT Hour (50%) · ×150%
                                        </p>
                                        <p className="text-lg font-semibold">
                                            {durationLabel(weekendMinutes)}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Hours:{' '}
                                            {overtimeTotals.weekend.hours} ·
                                            Minutes:{' '}
                                            {overtimeTotals.weekend.minutes}
                                        </p>
                                        <p>
                                            Gross OT pay:{' '}
                                            {payComplete
                                                ? formatPeso(weekendPay)
                                                : '—'}
                                        </p>
                                    </div>
                                </>
                            )}
                            <div className="rounded-lg border bg-primary/5 p-3">
                                <p className="text-xs text-muted-foreground">
                                    {isJo
                                        ? 'Total JO time · 100%'
                                        : 'Total OT time'}
                                </p>
                                <p className="text-lg font-semibold">
                                    {durationLabel(
                                        weekdayMinutes + weekendMinutes,
                                    )}
                                </p>
                                <p>
                                    {isJo ? 'Gross JO pay' : 'Gross OT pay'}:{' '}
                                    {payComplete
                                        ? formatPeso(grossCents / 100)
                                        : '—'}
                                </p>
                                {!isJo && (
                                    <p>
                                        Deduction (20%):{' '}
                                        {payComplete
                                            ? formatPeso(deductionCents / 100)
                                            : '—'}
                                    </p>
                                )}
                                {isJo && (
                                    <p>
                                        JO tax ({joTaxPercent || '0'}%):{' '}
                                        {payComplete
                                            ? formatPeso(deductionCents / 100)
                                            : '—'}
                                    </p>
                                )}
                                {isJo && (
                                    <p>
                                        Net JO pay:{' '}
                                        {payComplete ? formatPeso(netPay) : '—'}
                                    </p>
                                )}
                                {!isJo && (
                                    <p>
                                        Net OT pay:{' '}
                                        {payComplete ? formatPeso(netPay) : '—'}
                                    </p>
                                )}
                                {isJo && (
                                    <p className="text-xs text-muted-foreground">
                                        Daily rate ÷ 8 × total rendered hours
                                    </p>
                                )}
                                {!payComplete && (
                                    <p>
                                        Enter the report{' '}
                                        {isJo ? 'daily' : 'hourly'} rate to
                                        calculate pay.
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </section>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        disabled={
                            busy || (!report && !!dtrFile && !dtrPreviewStatus)
                        }
                        onClick={() => save(false)}
                    >
                        Save Draft
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={
                            busy || (!report && !!dtrFile && !dtrPreviewStatus)
                        }
                        onClick={() => save(true)}
                    >
                        Finalize
                    </Button>
                    {report && (
                        <>
                            <Link
                                href={preview(report.id)}
                                target="_blank"
                                className="rounded-md border px-4 py-2 text-sm"
                            >
                                Preview
                            </Link>
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
                                <Button
                                    type="submit"
                                    disabled={!canGeneratePdf}
                                >
                                    Generate PDF
                                </Button>
                            </form>
                            <form
                                method="post"
                                action={generateDocx(report.id).url}
                                target="_blank"
                            >
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrfToken}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={!canGenerate}
                                >
                                    Export DOCX
                                </Button>
                            </form>
                        </>
                    )}
                    {dirty && report && (
                        <span className="self-center text-sm text-muted-foreground">
                            Save changes before generating.
                        </span>
                    )}
                </div>
            </div>
            <Dialog
                open={addSignatoryType !== null}
                onOpenChange={(open) => {
                    if (!open && !savingSignatory) setAddSignatoryType(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add signatory</DialogTitle>
                        <DialogDescription>
                            Save a new{' '}
                            {addSignatoryType === 'prepared_by'
                                ? 'Prepared By'
                                : addSignatoryType === 'certified_correct'
                                  ? 'Certified Correct'
                                  : 'Approved By'}{' '}
                            option for your report.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={addSignatory} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="new-signatory-name">Name</Label>
                            <Input
                                id="new-signatory-name"
                                value={signatoryName}
                                onChange={(event) =>
                                    setSignatoryName(event.target.value)
                                }
                                required
                                maxLength={255}
                                autoFocus
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="new-signatory-position">
                                Position
                            </Label>
                            <Input
                                id="new-signatory-position"
                                value={signatoryPosition}
                                onChange={(event) =>
                                    setSignatoryPosition(event.target.value)
                                }
                                required
                                maxLength={255}
                            />
                        </div>
                        {signatoryError && (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {signatoryError}
                            </p>
                        )}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={savingSignatory}
                                onClick={() => setAddSignatoryType(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={savingSignatory}>
                                {savingSignatory ? 'Saving…' : 'Save signatory'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog
                open={aiIndex !== null}
                onOpenChange={(open) => {
                    if (!open) setAiIndex(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Review AI suggestion</DialogTitle>
                        <DialogDescription>
                            The original text stays unchanged until you approve
                            the suggestion.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        <div>
                            <strong>Original</strong>
                            <p className="mt-1 rounded-md border p-3">
                                {aiOriginal}
                            </p>
                        </div>
                        <div>
                            <strong>AI Suggested</strong>
                            <p className="mt-1 min-h-14 rounded-md border p-3">
                                {suggestion ||
                                    (busy
                                        ? 'Generating suggestion…'
                                        : errors?.ai ||
                                          'No suggestion available.')}
                            </p>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setAiIndex(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => requestSuggestion(aiOriginal)}
                        >
                            Regenerate
                        </Button>
                        <Button
                            disabled={!suggestion || busy}
                            onClick={acceptSuggestion}
                        >
                            Use Suggestion
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
