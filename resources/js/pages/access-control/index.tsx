import { Head, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy as destroyRole,
    store as storeRole,
    update as updateRole,
} from '@/routes/access-control/roles';
import { update as updateUserRoles } from '@/routes/access-control/users/roles';

type Permission = { id: number; key: string; label: string };
type Role = {
    id: number;
    name: string;
    permissions: Permission[];
    users_count: number;
};
type User = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    roles: Array<{ id: number; name: string }>;
};
type PageData = { errors: Record<string, string>; flash: { status?: string } };

export default function AccessControlIndex({
    availablePermissions,
    roles,
    users,
    canEditAdmins,
}: {
    availablePermissions: Permission[];
    roles: Role[];
    users: User[];
    canEditAdmins: boolean;
}) {
    const { errors, flash } = usePage<PageData>().props;
    const [editingRole, setEditingRole] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [permissionIds, setPermissionIds] = useState<number[]>([]);
    const [userRoleIds, setUserRoleIds] = useState<Record<number, number[]>>(
        {},
    );
    const [busy, setBusy] = useState(false);

    function resetRole() {
        setEditingRole(null);
        setName('');
        setPermissionIds([]);
    }

    function submitRole(event: FormEvent) {
        event.preventDefault();
        const options = {
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: resetRole,
        };
        const data = { name, permission_ids: permissionIds };
        if (editingRole !== null)
            router.put(updateRole(editingRole).url, data, options);
        else router.post(storeRole().url, data, options);
    }

    function selectedRoles(user: User): number[] {
        return userRoleIds[user.id] ?? user.roles.map((role) => role.id);
    }

    function toggleUserRole(user: User, roleId: number) {
        const selected = selectedRoles(user);
        setUserRoleIds((current) => ({
            ...current,
            [user.id]: selected.includes(roleId)
                ? selected.filter((id) => id !== roleId)
                : [...selected, roleId],
        }));
    }

    return (
        <>
            <Head title="Roles and permissions" />
            <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 md:px-8 md:py-9">
                <div>
                    <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                        Administration
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold">
                        Roles and permissions
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Choose who can see OT rates and pay computations.
                        Existing admins always have access.
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
                <div className="grid gap-6 lg:grid-cols-[350px_1fr]">
                    <form
                        onSubmit={submitRole}
                        className="space-y-4 rounded-2xl border bg-card p-5"
                    >
                        <h2 className="text-lg font-semibold">
                            {editingRole === null ? 'Create role' : 'Edit role'}
                        </h2>
                        <div className="space-y-1">
                            <Label htmlFor="role-name">Role name</Label>
                            <Input
                                id="role-name"
                                required
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                        </div>
                        <fieldset className="space-y-2">
                            <legend className="mb-2 text-sm font-medium">
                                Permissions
                            </legend>
                            {availablePermissions.map((permission) => (
                                <label
                                    key={permission.id}
                                    className="flex items-start gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        className="mt-1"
                                        checked={permissionIds.includes(
                                            permission.id,
                                        )}
                                        onChange={() =>
                                            setPermissionIds((current) =>
                                                current.includes(permission.id)
                                                    ? current.filter(
                                                          (id) =>
                                                              id !==
                                                              permission.id,
                                                      )
                                                    : [
                                                          ...current,
                                                          permission.id,
                                                      ],
                                            )
                                        }
                                    />
                                    <span>{permission.label}</span>
                                </label>
                            ))}
                        </fieldset>
                        <div className="flex gap-2">
                            <Button disabled={busy} type="submit">
                                {editingRole === null
                                    ? 'Create role'
                                    : 'Save role'}
                            </Button>
                            {editingRole !== null && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={resetRole}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>
                    <section className="rounded-2xl border bg-card p-5">
                        <h2 className="mb-4 text-lg font-semibold">Roles</h2>
                        <div className="space-y-3">
                            {roles.map((role) => (
                                <div
                                    key={role.id}
                                    className="rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <p className="font-medium">
                                                {role.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {role.users_count} users ·{' '}
                                                {role.permissions.length
                                                    ? role.permissions
                                                          .map(
                                                              (permission) =>
                                                                  permission.label,
                                                          )
                                                          .join(', ')
                                                    : 'No permissions'}
                                            </p>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setEditingRole(role.id);
                                                    setName(role.name);
                                                    setPermissionIds(
                                                        role.permissions.map(
                                                            (permission) =>
                                                                permission.id,
                                                        ),
                                                    );
                                                }}
                                            >
                                                Edit
                                            </Button>
                                            {role.name !== 'Staff' && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="destructive"
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                `Delete ${role.name}? Users assigned to it will lose its permissions.`,
                                                            )
                                                        )
                                                            router.delete(
                                                                destroyRole(
                                                                    role.id,
                                                                ).url,
                                                            );
                                                    }}
                                                >
                                                    Delete
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                </div>
                <section className="rounded-2xl border bg-card p-5">
                    <h2 className="text-lg font-semibold">User roles</h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Assign one or more roles to each user. Admins retain
                        access through their existing admin status.
                    </p>
                    <div className="grid gap-3 md:grid-cols-2">
                        {users.map((user) => (
                            <div
                                key={user.id}
                                className="rounded-lg border p-4"
                            >
                                <p className="font-medium">
                                    {user.name}{' '}
                                    {user.is_admin && (
                                        <span className="text-xs text-muted-foreground">
                                            (Admin)
                                        </span>
                                    )}
                                </p>
                                <p className="mb-3 text-xs text-muted-foreground">
                                    {user.email}
                                </p>
                                <div className="flex flex-wrap gap-3">
                                    {roles.map((role) => (
                                        <label
                                            key={role.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                disabled={
                                                    user.is_admin &&
                                                    !canEditAdmins
                                                }
                                                checked={selectedRoles(
                                                    user,
                                                ).includes(role.id)}
                                                onChange={() =>
                                                    toggleUserRole(
                                                        user,
                                                        role.id,
                                                    )
                                                }
                                            />
                                            {role.name}
                                        </label>
                                    ))}
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    className="mt-3"
                                    disabled={
                                        (user.is_admin && !canEditAdmins) ||
                                        selectedRoles(user).length === 0 ||
                                        busy
                                    }
                                    onClick={() =>
                                        router.put(
                                            updateUserRoles(user.id).url,
                                            { role_ids: selectedRoles(user) },
                                            {
                                                onStart: () => setBusy(true),
                                                onFinish: () => setBusy(false),
                                                onSuccess: () =>
                                                    setUserRoleIds(
                                                        (current) => {
                                                            const next = {
                                                                ...current,
                                                            };
                                                            delete next[
                                                                user.id
                                                            ];
                                                            return next;
                                                        },
                                                    ),
                                            },
                                        )
                                    }
                                >
                                    Save roles
                                </Button>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}
