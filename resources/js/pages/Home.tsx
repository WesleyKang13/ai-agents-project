import { Head, Link } from '@inertiajs/react';
import AppLayout from '../layouts/AppLayout';
import type { SharedProps } from '../types';

export default function Home({ projectName, navigation }: SharedProps) {
    return (
        <AppLayout>
            <Head title="Home" />
            <section className="grid items-center gap-12 py-8 md:grid-cols-[1.2fr_1fr] md:py-16">
                <div className="flex flex-col items-start gap-6">
                    <p className="text-xs font-semibold tracking-[0.2em] text-muted uppercase">
                        Your personal agent workspace
                    </p>
                    <h1 className="max-w-xl text-4xl leading-tight font-semibold tracking-tight sm:text-5xl">
                        Good work starts with a little space.
                    </h1>
                    <p className="max-w-lg text-base leading-7 text-muted">
                        Organise your Codex agents by project, follow their tasks and keep each
                        conversation close to the work.
                    </p>
                    <Link
                        href={navigation.projects}
                        className="inline-flex items-center gap-4 rounded-full bg-forest px-6 py-3 text-sm font-medium text-cream transition-colors hover:bg-ink"
                    >
                        Open Projects <span aria-hidden="true">↗</span>
                    </Link>
                </div>
                <aside className="rounded-3xl border border-line bg-sage/60 p-8 sm:p-10">
                    <p className="text-xs font-semibold tracking-widest text-muted uppercase">
                        A place to build together
                    </p>
                    <h2 className="mt-6 text-2xl font-medium tracking-tight">
                        Your projects.
                        <br />
                        Room for your whole team.
                    </h2>
                    <p className="mt-5 leading-7 text-muted">
                        Add an existing folder, open its Workshop and create the agents you need.
                        Each agent keeps its own instructions, conversation and activity.
                    </p>
                </aside>
            </section>
        </AppLayout>
    );
}
