import { Head, Link, router, usePage } from '@inertiajs/react';
import { GripVertical, Plus, Sparkles, Trash2 } from 'lucide-react';
import {
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
import { store as uploadDtr, show as reviewDtr } from '@/routes/reports/dtr';
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
    task_accomplished: string;
    original_task_accomplished: string;
    ai_suggested_task_accomplished: string;
    ai_enhanced: boolean;
};
type Report = {
    id: number;
    report_month: number;
    report_year: number;
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

export default function ReportEditor({
    report,
    signatories,
}: {
    report: Report | null;
    signatories: Record<string, Signatory[]>;
}) {
    const { errors, flash, csrfToken } = usePage<PageData>().props;
    const [month, setMonth] = useState(
        report?.report_month ?? new Date().getMonth() + 1,
    );
    const [year, setYear] = useState(
        report?.report_year ?? new Date().getFullYear(),
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
        const payload = {
            report_month: month,
            report_year: year,
            prepared_by_id: prepared,
            certified_by_id: certified,
            approved_by_id: approved,
            ...overrides,
            prepared_name: '',
            certified_name: '',
            approved_name: '',
            entries: entries.map(({ key: _key, ...entry }) => entry),
            finalize,
        };
        const options = {
            preserveScroll: true,
            preserveState: false,
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

    function importDtr(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!report || !dtrFile) return;
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

    const canGenerate =
        report &&
        !dirty &&
        entries.length > 0 &&
        entries.every(
            (entry) => entry.task_accomplished.trim() && entry.quantity.trim(),
        );

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
                            report.
                        </p>
                    </div>
                    <div>
                        <Label htmlFor="period">Reporting period</Label>
                        <Input
                            id="period"
                            type="month"
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
                {report && (
                    <section className="rounded-2xl border bg-card p-5 shadow-sm shadow-black/2 md:p-6">
                        <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                            02 · Import
                        </p>
                        <h2 className="mt-1 text-lg font-semibold">
                            Daily Time Record
                        </h2>
                        <p className="mb-3 text-sm text-muted-foreground">
                            Upload a text-based Civil Service Form No. 48 PDF.
                            You will review detected dates before importing
                            them.
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
                                    onChange={(event) =>
                                        setDtrFile(
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            </div>
                            <Button type="submit" disabled={!dtrFile || busy}>
                                Process DTR
                            </Button>
                        </form>
                        {report.dtr_imports?.length > 0 && (
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
                )}
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
                                Enter any quantity, such as 2h 15m, 3 documents,
                                or 1 module. Drag a task onto another task to
                                place it on the correct date. Filled tasks swap
                                places.
                            </p>
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
                                            key: crypto.randomUUID(),
                                            accomplishment_date: '',
                                            quantity: '',
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
                                className="grid gap-3 rounded-lg border p-3 md:grid-cols-[150px_110px_1fr_auto]"
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
                                    <Input
                                        value={entry.quantity}
                                        onChange={(event) =>
                                            editEntry(index, {
                                                quantity: event.target.value,
                                            })
                                        }
                                        placeholder="e.g. 2h 15m or 3 documents"
                                    />
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
                </section>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        disabled={busy}
                        onClick={() => save(false)}
                    >
                        Save Draft
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
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
                                <Button type="submit" disabled={!canGenerate}>
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
