import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../layouts/AppLayout';

export default function Login({ loginUrl }: { loginUrl: string }) {
    const form = useForm({ email: '', password: '' });
    return (
        <AppLayout>
            <Head title="Sign in" />
            <section className="mx-auto max-w-md rounded-3xl border border-line bg-white/60 p-7 sm:p-10">
                <p className="text-xs font-semibold tracking-[0.2em] text-muted uppercase">
                    Your private workshop
                </p>
                <h1 className="mt-3 text-3xl font-semibold tracking-tight">Welcome back.</h1>
                <p className="mt-3 leading-7 text-muted">
                    Sign in to open your projects and their agents’ conversations.
                </p>
                <form
                    className="mt-7 grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(loginUrl, { onFinish: () => form.reset('password') });
                    }}
                >
                    <label className="grid gap-2 text-sm font-medium">
                        Email
                        <input
                            name="email"
                            type="email"
                            autoComplete="username"
                            required
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            className="rounded-xl border border-line bg-cream px-4 py-3"
                        />
                    </label>
                    <label className="grid gap-2 text-sm font-medium">
                        Password
                        <input
                            name="password"
                            type="password"
                            autoComplete="current-password"
                            required
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            className="rounded-xl border border-line bg-cream px-4 py-3"
                        />
                    </label>
                    {Object.values(form.errors).map((error) => (
                        <p key={error} role="alert" className="text-sm text-red-800">
                            {error}
                        </p>
                    ))}
                    <button
                        disabled={form.processing}
                        className="rounded-full bg-forest px-6 py-3 font-medium text-cream hover:bg-ink disabled:opacity-50"
                    >
                        {form.processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
                <p className="mt-6 text-sm leading-6 text-muted">
                    This is a personal workspace. Ask its owner for access; public registration is
                    closed.
                </p>
            </section>
        </AppLayout>
    );
}
