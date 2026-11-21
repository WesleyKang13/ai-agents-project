import { Head } from '@inertiajs/react';
import AppLayout from '../layouts/AppLayout';
import type { SharedProps } from '../types';

type WorkshopProps = SharedProps & {
    agents: { id: string; name: string }[];
    milestone: string;
};

export default function Workshop({ agents, milestone }: WorkshopProps) {
    return (
        <AppLayout>
            <Head title="Workshop" />
            <div className="flex flex-wrap items-end justify-between gap-5">
                <div>
                    <p className="text-xs font-semibold tracking-[0.2em] text-muted uppercase">
                        Your workspace
                    </p>
                    <h1 className="mt-3 text-4xl font-semibold tracking-tight">Workshop</h1>
                    <p className="mt-3 max-w-lg leading-7 text-muted">
                        A shared space for your Codex agents and the work you’ll do together.
                    </p>
                </div>
                <p className="rounded-full border border-line px-4 py-2 text-sm text-muted">
                    {agents.length} agents
                </p>
            </div>
            <section
                aria-label="Agents"
                className="mt-10 rounded-3xl border border-line bg-white/50 p-8 sm:p-16"
            >
                {agents.length === 0 ? (
                    <div className="mx-auto flex max-w-md flex-col items-center gap-5 py-10 text-center sm:py-16">
                        <span
                            aria-hidden="true"
                            className="grid size-16 place-items-center rounded-2xl bg-sage text-3xl font-light text-forest"
                        >
                            ＋
                        </span>
                        <h2 className="text-2xl font-medium tracking-tight">
                            Your workshop is waiting.
                        </h2>
                        <p className="leading-7 text-muted">
                            Agents will appear here once added. Each one will have its own station,
                            with a place to follow tasks and open a conversation.
                        </p>
                        <p className="rounded-full bg-sage px-4 py-2 text-xs font-medium text-forest">
                            Agent connections are coming in a later milestone
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2">
                        {agents.map((agent) => (
                            <li key={agent.id} className="rounded-2xl bg-sage p-6 font-medium">
                                {agent.name}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            <div className="mt-6 flex flex-wrap justify-between gap-3 text-xs text-muted">
                <p>Current milestone: {milestone}</p>
                <p>Next up: Codex App Server integration spike</p>
            </div>
        </AppLayout>
    );
}
