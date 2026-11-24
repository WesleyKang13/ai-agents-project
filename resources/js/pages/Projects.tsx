import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppLayout from '../layouts/AppLayout';
import type { ProjectSummary } from '../types';

type FolderListing = {
    roots: string[];
    path: string | null;
    parent: string | null;
    directories: { name: string; path: string }[];
};
type Props = {
    projects: (ProjectSummary & { agent_count: number })[];
    createProjectUrl: string;
    browseFoldersUrl: string;
};
const button =
    'rounded-full bg-forest px-5 py-2.5 text-sm font-medium text-cream hover:bg-ink disabled:opacity-50';
const secondary =
    'rounded-full border border-line px-4 py-2 text-sm font-medium hover:bg-sage disabled:opacity-50';

export default function Projects({ projects, createProjectUrl, browseFoldersUrl }: Props) {
    const form = useForm({ name: '', folder: '' });
    const [adding, setAdding] = useState(false);
    const [listing, setListing] = useState<FolderListing | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const browsing = useRef(false);

    async function browse(path?: string) {
        if (browsing.current) return;
        browsing.current = true;
        setLoading(true);
        setError('');
        try {
            const response = await fetch(
                `${browseFoldersUrl}${path ? `?${new URLSearchParams({ path })}` : ''}`,
                { headers: { Accept: 'application/json' } },
            );
            if (response.status === 401 || response.status === 419) {
                window.location.assign('/login');
                return;
            }
            const data = await response.json();
            if (!response.ok)
                throw new Error(
                    data.errors
                        ? String(Object.values(data.errors).flat()[0])
                        : (data.message ?? 'Could not browse folders.'),
                );
            setListing(data);
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'Could not browse folders.');
        } finally {
            browsing.current = false;
            setLoading(false);
        }
    }

    return (
        <AppLayout>
            <Head title="Projects" />
            <div className="flex flex-wrap items-end justify-between gap-5">
                <div>
                    <p className="text-xs font-semibold tracking-[0.2em] text-muted uppercase">
                        Your work, organised
                    </p>
                    <h1 className="mt-3 text-4xl font-semibold tracking-tight">Projects</h1>
                    <p className="mt-3 leading-7 text-muted">
                        Choose a project to open its Workshop.
                    </p>
                </div>
                <button
                    className={button}
                    aria-expanded={adding}
                    aria-controls="add-project"
                    onClick={() => {
                        setAdding((current) => !current);
                        if (!listing) void browse();
                    }}
                >
                    Add existing project
                </button>
            </div>

            {adding && (
                <section
                    id="add-project"
                    aria-label="Add existing project"
                    className="mt-8 rounded-3xl border border-line bg-white/60 p-6 sm:p-8"
                >
                    <h2 className="text-xl font-semibold">Add an existing project</h2>
                    <p className="mt-3 text-sm leading-6 text-muted">
                        Choose a folder on the WSL machine running Laravel and Codex. Registration
                        uses the existing files in place.
                    </p>
                    <form
                        className="mt-6 grid gap-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(createProjectUrl);
                        }}
                    >
                        <label className="grid gap-2 text-sm font-medium">
                            Project name
                            <input
                                required
                                maxLength={120}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className="rounded-xl border border-line bg-cream px-4 py-3"
                            />
                            {form.errors.name && (
                                <span role="alert" className="text-red-800">
                                    {form.errors.name}
                                </span>
                            )}
                        </label>
                        <div
                            className="grid gap-3 rounded-2xl border border-line p-4"
                            aria-label="Server folder browser"
                            aria-busy={loading}
                        >
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    disabled={loading}
                                    className={secondary}
                                    onClick={() => void browse()}
                                >
                                    Allowed roots
                                </button>
                                {listing?.parent && (
                                    <button
                                        type="button"
                                        disabled={loading}
                                        className={secondary}
                                        onClick={() => void browse(listing.parent!)}
                                    >
                                        Up one folder
                                    </button>
                                )}
                            </div>
                            <p className="text-sm break-all text-muted">
                                {listing?.path ?? 'Select an allowed root'}
                            </p>
                            {listing?.path && (
                                <button
                                    type="button"
                                    disabled={loading}
                                    className={`${secondary} justify-self-start`}
                                    onClick={() => {
                                        form.setData('folder', listing.path!);
                                        form.clearErrors('folder');
                                    }}
                                >
                                    Use this folder
                                </button>
                            )}
                            <ul className="grid max-h-64 gap-1 overflow-y-auto">
                                {(listing?.path
                                    ? listing.directories
                                    : listing?.roots.map((path) => ({ name: path, path }))
                                )?.map((directory, index) => (
                                    <li key={`${directory.path}-${index}`}>
                                        <button
                                            type="button"
                                            disabled={loading}
                                            onClick={() => void browse(directory.path)}
                                            className="w-full rounded-lg px-3 py-2 text-left text-sm break-all hover:bg-sage"
                                        >
                                            {directory.name} <span aria-hidden="true">↗</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            {listing?.path && listing.directories.length === 0 && (
                                <p className="text-sm text-muted">
                                    No accessible subfolders. You can use this folder.
                                </p>
                            )}
                            {listing?.roots.length === 0 && (
                                <p role="status" className="text-sm text-muted">
                                    No allowed root is accessible. Check PROJECT_ALLOWED_ROOTS and
                                    server permissions.
                                </p>
                            )}
                            {loading && (
                                <p role="status" className="text-sm text-muted">
                                    Loading folders…
                                </p>
                            )}
                            {error && (
                                <p role="alert" className="text-sm text-red-800">
                                    {error}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-2 text-sm">
                            <p className="font-medium">Selected folder</p>
                            <p className="break-all text-muted">
                                {form.data.folder ||
                                    'Browse to your project and choose “Use this folder”.'}
                            </p>
                            {form.errors.folder && (
                                <p role="alert" className="text-red-800">
                                    {form.errors.folder}
                                </p>
                            )}
                        </div>
                        <button
                            disabled={form.processing || !form.data.folder}
                            className={`${button} justify-self-start`}
                        >
                            {form.processing ? 'Adding…' : 'Add project'}
                        </button>
                    </form>
                </section>
            )}

            <div className="mt-9 grid gap-5 sm:grid-cols-2">
                {projects.map((project) => (
                    <Link
                        key={project.id}
                        href={project.workshop_url}
                        className="min-w-0 rounded-3xl border border-line bg-white/60 p-7 hover:bg-sage/60"
                    >
                        <h2 className="text-xl font-semibold">{project.name}</h2>
                        <p className="mt-3 text-sm leading-6 break-all text-muted">
                            {project.folder}
                        </p>
                        <div className="mt-5 flex flex-wrap gap-3 text-sm text-forest">
                            <span>
                                {project.agent_count}{' '}
                                {project.agent_count === 1 ? 'agent' : 'agents'}
                            </span>
                            {project.is_legacy && <span>Legacy workspace</span>}
                            {project.is_temporary && <span>Temporary</span>}
                        </div>
                        <p className="mt-5 text-sm font-medium">
                            Open Workshop <span aria-hidden="true">↗</span>
                        </p>
                    </Link>
                ))}
            </div>
            {projects.length === 0 && (
                <section className="mt-9 rounded-3xl border border-line bg-sage/50 p-10 text-center">
                    <h2 className="text-xl font-medium">Your first project starts here.</h2>
                    <p className="mt-3 text-sm leading-7 text-muted">
                        Add an existing folder, then create its first agent in the Workshop.
                    </p>
                </section>
            )}
        </AppLayout>
    );
}
