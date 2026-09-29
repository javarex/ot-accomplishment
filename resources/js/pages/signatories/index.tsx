import { Head, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, store, update } from '@/routes/signatories';

type Signatory = {
    id: number;
    name: string;
    position: string;
    signatory_type: string;
    is_active: boolean;
};
type PageData = { errors: Record<string, string>; flash: { status?: string } };
const types = [
    { value: 'prepared_by', label: 'Prepared By' },
    { value: 'certified_correct', label: 'Certified Correct' },
    { value: 'approved', label: 'Approved By' },
];

export default function SignatoriesIndex({
    signatories,
}: {
    signatories: Signatory[];
}) {
    const { errors, flash } = usePage<PageData>().props;
    const [editing, setEditing] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [position, setPosition] = useState('');
    const [type, setType] = useState('prepared_by');
    const [active, setActive] = useState(true);
    const [busy, setBusy] = useState(false);

    function reset() {
        setEditing(null);
        setName('');
        setPosition('');
        setType('prepared_by');
        setActive(true);
    }
    function submit(event: FormEvent) {
        event.preventDefault();
        const payload = {
            name,
            position,
            signatory_type: type,
            is_active: active,
        };
        const options = {
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: reset,
        };
        if (editing) router.put(update(editing).url, payload, options);
        else router.post(store().url, payload, options);
    }

    return (
        <>
            <Head title="Signatories" />
            <div className="mx-auto grid w-full max-w-7xl gap-6 px-4 py-6 md:grid-cols-[350px_1fr] md:px-8 md:py-9">
                <div>
                    <p className="text-xs font-semibold tracking-[0.18em] text-primary uppercase">
                        Report configuration
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Signatories
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Save frequently used names and positions for reports.
                    </p>
                    {flash?.status && (
                        <p className="mt-3 rounded-md border bg-muted p-3 text-sm">
                            {flash.status}
                        </p>
                    )}
                    {Object.values(errors ?? {}).map((error, index) => (
                        <p
                            key={index}
                            className="mt-2 text-sm text-destructive"
                        >
                            {error}
                        </p>
                    ))}
                    <form
                        onSubmit={submit}
                        className="mt-5 grid gap-4 rounded-2xl border bg-card p-5 shadow-sm shadow-black/2"
                    >
                        <h2 className="font-medium">
                            {editing ? 'Edit signatory' : 'Add signatory'}
                        </h2>
                        <div>
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                required
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="position">Position</Label>
                            <Input
                                id="position"
                                required
                                value={position}
                                onChange={(event) =>
                                    setPosition(event.target.value)
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="type">Signatory section</Label>
                            <select
                                id="type"
                                className="h-9 w-full rounded-md border bg-background px-2 text-sm"
                                value={type}
                                onChange={(event) =>
                                    setType(event.target.value)
                                }
                            >
                                {types.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {editing && (
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={active}
                                    onChange={(event) =>
                                        setActive(event.target.checked)
                                    }
                                />{' '}
                                Active
                            </label>
                        )}
                        <div className="flex gap-2">
                            <Button disabled={busy} type="submit">
                                Save
                            </Button>
                            {editing && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={reset}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>
                </div>
                <div className="overflow-x-auto rounded-2xl border bg-card shadow-sm shadow-black/2">
                    <table className="w-full min-w-[500px] text-left text-sm">
                        <thead className="border-b bg-muted/50 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            <tr>
                                <th className="p-3">Name</th>
                                <th className="p-3">Position</th>
                                <th className="p-3">Section</th>
                                <th className="p-3">Status</th>
                                <th className="p-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {signatories.map((person) => (
                                <tr
                                    key={person.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="p-3 font-medium">
                                        {person.name}
                                    </td>
                                    <td className="p-3">{person.position}</td>
                                    <td className="p-3">
                                        {
                                            types.find(
                                                (item) =>
                                                    item.value ===
                                                    person.signatory_type,
                                            )?.label
                                        }
                                    </td>
                                    <td className="p-3">
                                        {person.is_active
                                            ? 'Active'
                                            : 'Inactive'}
                                    </td>
                                    <td className="p-3">
                                        <div className="flex gap-3">
                                            <button
                                                className="underline"
                                                onClick={() => {
                                                    setEditing(person.id);
                                                    setName(person.name);
                                                    setPosition(
                                                        person.position,
                                                    );
                                                    setType(
                                                        person.signatory_type,
                                                    );
                                                    setActive(person.is_active);
                                                }}
                                            >
                                                Edit
                                            </button>
                                            {person.is_active && (
                                                <button
                                                    className="text-destructive underline"
                                                    onClick={() => {
                                                        if (
                                                            confirm(
                                                                'Deactivate this signatory? Existing reports will retain the reference.',
                                                            )
                                                        )
                                                            router.delete(
                                                                destroy(
                                                                    person.id,
                                                                ).url,
                                                            );
                                                    }}
                                                >
                                                    Deactivate
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {signatories.length === 0 && (
                        <p className="p-8 text-center text-muted-foreground">
                            No signatories saved yet.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
