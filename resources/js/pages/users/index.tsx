import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store as startImpersonating } from '@/routes/impersonation';
import {
    destroy as deleteUser,
    index as usersIndex,
    restore as restoreUser,
    store as createUser,
    update as updateUser,
} from '@/routes/users';

type Role = { id: number; name: string };
type User = {
    id: number;
    name: string;
    email: string;
    username: string;
    is_admin: boolean;
    deleted_at: string | null;
    roles: Role[];
};
type PageData = { errors: Record<string, string>; flash: { status?: string } };

export default function UsersIndex({
    users,
    roles,
    search,
    canImpersonate,
    currentUserId,
}: {
    users: {
        data: User[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    roles: Role[];
    search: string;
    canImpersonate: boolean;
    currentUserId: number;
}) {
    const { errors, flash } = usePage<PageData>().props;
    const [editing, setEditing] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [username, setUsername] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [roleIds, setRoleIds] = useState<number[]>([]);
    const [searchText, setSearchText] = useState(search);
    const [busy, setBusy] = useState(false);

    function reset() {
        setEditing(null);
        setName('');
        setEmail('');
        setUsername('');
        setPassword('');
        setPasswordConfirmation('');
        setRoleIds([]);
    }

    function selectUser(user: User) {
        setEditing(user.id);
        setName(user.name);
        setEmail(user.email);
        setUsername(user.username);
        setPassword('');
        setPasswordConfirmation('');
        setRoleIds(user.roles.map((role) => role.id));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const data = {
            name,
            email,
            username,
            role_ids: roleIds,
            ...(editing === null || password
                ? { password, password_confirmation: passwordConfirmation }
                : {}),
        };
        const options = {
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: reset,
        };
        if (editing === null) router.post(createUser().url, data, options);
        else router.put(updateUser(editing).url, data, options);
    }

    return (
        <>
            <Head title="Users" />
            <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 md:px-8 md:py-9">
                <div>
                    <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                        Administration
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold">Users</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Create accounts, assign roles, and manage access.
                        Deactivated users cannot sign in; their reports remain
                        available.
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
                <div className="grid gap-6 lg:grid-cols-[340px_1fr]">
                    <form
                        onSubmit={submit}
                        className="space-y-4 rounded-2xl border bg-card p-5"
                    >
                        <h2 className="text-lg font-semibold">
                            {editing === null ? 'Create user' : 'Edit user'}
                        </h2>
                        <div className="space-y-1">
                            <Label htmlFor="user-name">Name</Label>
                            <Input
                                id="user-name"
                                required
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="user-username">Username</Label>
                            <Input
                                id="user-username"
                                value={username}
                                onChange={(event) =>
                                    setUsername(event.target.value)
                                }
                                required
                                maxLength={255}
                                autoComplete="username"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="user-email">Email</Label>
                            <Input
                                id="user-email"
                                required
                                type="email"
                                value={email}
                                onChange={(event) =>
                                    setEmail(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="user-password">
                                {editing === null
                                    ? 'Password'
                                    : 'New password (optional)'}
                            </Label>
                            <Input
                                id="user-password"
                                required={editing === null}
                                type="password"
                                autoComplete="new-password"
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="user-password-confirmation">
                                Confirm password
                            </Label>
                            <Input
                                id="user-password-confirmation"
                                required={!!password}
                                type="password"
                                autoComplete="new-password"
                                value={passwordConfirmation}
                                onChange={(event) =>
                                    setPasswordConfirmation(event.target.value)
                                }
                            />
                        </div>
                        <fieldset className="space-y-2">
                            <legend className="mb-2 text-sm font-medium">
                                Roles
                            </legend>
                            {roles.map((role) => (
                                <label
                                    key={role.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        checked={roleIds.includes(role.id)}
                                        onChange={() =>
                                            setRoleIds((current) =>
                                                current.includes(role.id)
                                                    ? current.filter(
                                                          (id) =>
                                                              id !== role.id,
                                                      )
                                                    : [...current, role.id],
                                            )
                                        }
                                    />
                                    {role.name}
                                </label>
                            ))}
                        </fieldset>
                        <div className="flex gap-2">
                            <Button
                                disabled={busy || roleIds.length === 0}
                                type="submit"
                            >
                                {editing === null
                                    ? 'Create user'
                                    : 'Save changes'}
                            </Button>
                            {editing !== null && (
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
                    <section className="rounded-2xl border bg-card p-5">
                        <h2 className="mb-3 text-lg font-semibold">Accounts</h2>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.get(
                                    usersIndex().url,
                                    { search: searchText },
                                    { preserveState: true },
                                );
                            }}
                            className="mb-4 flex gap-2"
                        >
                            <Input
                                aria-label="Search users"
                                placeholder="Search name, username or email"
                                value={searchText}
                                onChange={(event) =>
                                    setSearchText(event.target.value)
                                }
                            />
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                        <div className="space-y-3">
                            {users.data.map((user) => (
                                <div
                                    key={user.id}
                                    className="rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-medium">
                                                {user.name}{' '}
                                                {user.is_admin && (
                                                    <span className="text-xs text-muted-foreground">
                                                        (Admin)
                                                    </span>
                                                )}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {user.username} · {user.email}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {user.deleted_at
                                                    ? 'Deactivated'
                                                    : 'Active'}{' '}
                                                ·{' '}
                                                {user.roles
                                                    .map((role) => role.name)
                                                    .join(', ') || 'No roles'}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {user.deleted_at ? (
                                                (canImpersonate ||
                                                    !user.is_admin) && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                restoreUser(
                                                                    user.id,
                                                                ).url,
                                                            )
                                                        }
                                                    >
                                                        Restore
                                                    </Button>
                                                )
                                            ) : (
                                                <>
                                                    {(canImpersonate ||
                                                        !user.is_admin) && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                selectUser(user)
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                    )}
                                                    {canImpersonate &&
                                                        !user.is_admin &&
                                                        user.id !==
                                                            currentUserId && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => {
                                                                    if (
                                                                        window.confirm(
                                                                            `Impersonate ${user.name}?`,
                                                                        )
                                                                    )
                                                                        router.post(
                                                                            startImpersonating(
                                                                                user.id,
                                                                            )
                                                                                .url,
                                                                        );
                                                                }}
                                                            >
                                                                Impersonate
                                                            </Button>
                                                        )}
                                                    {!user.is_admin &&
                                                        user.id !==
                                                            currentUserId && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="destructive"
                                                                onClick={() => {
                                                                    if (
                                                                        window.confirm(
                                                                            `Deactivate ${user.name}? This user will no longer be able to sign in.`,
                                                                        )
                                                                    )
                                                                        router.delete(
                                                                            deleteUser(
                                                                                user.id,
                                                                            )
                                                                                .url,
                                                                        );
                                                                }}
                                                            >
                                                                Deactivate
                                                            </Button>
                                                        )}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                        {users.data.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No users found.
                            </p>
                        )}
                        <div className="mt-5 flex flex-wrap gap-2">
                            {users.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        className={`rounded-md border px-3 py-1.5 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'bg-card'}`}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ) : (
                                    <span
                                        key={index}
                                        className="rounded-md border px-3 py-1.5 text-sm text-muted-foreground"
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ),
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}
