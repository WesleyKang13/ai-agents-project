import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../layouts/AppLayout';
import type {
    AgentActivity,
    AgentApproval,
    AgentMessage,
    WorkshopAgent,
    WorkshopState,
} from '../types';

type Props = {
    workshop: WorkshopState | null;
    occupied: boolean;
    workspaces: { key: string; name: string; path: string; available: boolean }[];
    createAgentUrl: string;
};
const labels: Record<string, string> = {
    offline: 'Worker offline',
    idle: 'Ready',
    queued: 'Starting task',
    running: 'Working',
    waiting_permission: 'Needs your permission',
    stopping: 'Stopping',
    recovering: 'Checking conversation',
    needs_recovery: 'Needs attention',
};
const button =
    'rounded-full bg-forest px-5 py-2.5 text-sm font-medium text-cream hover:bg-ink disabled:cursor-not-allowed disabled:opacity-50';
const secondary =
    'rounded-full border border-line bg-white/60 px-4 py-2.5 text-sm font-medium hover:bg-sage disabled:cursor-not-allowed disabled:opacity-50';

async function post<T>(url: string, body: object): Promise<T> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
        },
        body: JSON.stringify(body),
    });
    if (response.status === 401 || response.status === 419) {
        window.location.assign('/login');
        throw new Error('Please sign in again.');
    }
    const data = await response.json();
    if (!response.ok)
        throw new Error(
            data.errors
                ? String(Object.values(data.errors).flat()[0])
                : (data.message ?? 'The action could not be completed. Please try again.'),
        );
    return data;
}
function upsert<T extends { id: number }>(items: T[], item: T): T[] {
    return items.some((current) => current.id === item.id)
        ? items.map((current) => (current.id === item.id ? item : current))
        : [...items, item];
}

export default function Workshop({ workshop, occupied, workspaces, createAgentUrl }: Props) {
    const [state, setState] = useState(workshop);
    const [name, setName] = useState('');
    const [workspace, setWorkspace] = useState(
        workspaces.find((item) => item.available)?.key ?? '',
    );
    const [text, setText] = useState('');
    const [steering, setSteering] = useState('');
    const [panel, setPanel] = useState<'conversation' | 'activity'>('conversation');
    const [open, setOpen] = useState(true);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [connection, setConnection] = useState('Connecting…');
    const submitting = useRef(false);
    const retry = useRef<{ action: string; payload: string; key: string } | null>(null);
    const cursor = useRef(workshop?.cursor ?? 0);
    const end = useRef<HTMLDivElement>(null);
    const agent = state?.agent;
    const agentId = agent?.id;
    const eventsUrl = agent?.urls.events;

    useEffect(() => {
        if (!eventsUrl) return;
        const source = new EventSource(`${eventsUrl}?after=${cursor.current}`);
        source.onopen = () => setConnection('Live updates connected');
        source.onerror = () => setConnection('Reconnecting to saved updates…');
        source.addEventListener('health', (event: MessageEvent) => {
            const updated = JSON.parse(event.data) as WorkshopAgent;
            setState((current) => (current ? { ...current, agent: updated } : current));
        });
        source.addEventListener('workshop', (event: MessageEvent) => {
            const update = JSON.parse(event.data);
            if (update.id <= cursor.current) return;
            cursor.current = update.id;
            setState((current) => {
                if (!current) return current;
                const next = { ...current, cursor: update.id };
                if (update.type === 'status') next.agent = update.data as WorkshopAgent;
                if (update.type === 'message')
                    next.messages = upsert(current.messages, update.data as AgentMessage);
                if (update.type === 'activity')
                    next.activity = [
                        ...current.activity,
                        { id: update.id, ...update.data } as AgentActivity,
                    ].slice(-100);
                if (update.type === 'approval')
                    next.approvals = upsert(current.approvals, update.data as AgentApproval);
                if (update.type === 'turn') next.turns = upsert(current.turns, update.data);
                return next;
            });
        });
        return () => source.close();
    }, [agentId, eventsUrl]);

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'nearest' });
    }, [state?.messages, panel, open]);

    async function act(action: string, url: string, payload: object, after?: () => void) {
        if (submitting.current) return;
        submitting.current = true;
        setBusy(action);
        setError('');
        const fingerprint = JSON.stringify(payload);
        if (
            !retry.current ||
            retry.current.action !== action ||
            retry.current.payload !== fingerprint
        )
            retry.current = { action, payload: fingerprint, key: crypto.randomUUID() };
        try {
            await post(url, { ...payload, request_key: retry.current.key });
            retry.current = null;
            after?.();
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'Please try again.');
        } finally {
            submitting.current = false;
            setBusy(null);
        }
    }

    const active = agent?.active_turn_id != null;
    const canStop =
        agent?.online &&
        active &&
        ['queued', 'running', 'waiting_permission'].includes(agent.status);
    const pending =
        state?.approvals.filter(
            (item) =>
                item.turn_id === agent?.active_turn_id &&
                ['pending', 'deciding'].includes(item.status),
        ) ?? [];

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
                        A little space to think, build and make progress together.
                    </p>
                </div>
                <span className="rounded-full border border-line px-4 py-2 text-sm text-muted">
                    {agent ? '1 agent' : 'Your first agent'}
                </span>
            </div>
            {error && (
                <div
                    role="alert"
                    className="mt-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"
                >
                    {error}
                </div>
            )}
            {!agent ? (
                <section
                    className="mt-10 rounded-3xl border border-line bg-white/60 p-7 sm:p-12"
                    aria-labelledby="create-heading"
                >
                    <div className="mx-auto max-w-lg">
                        <p className="text-forest">✳</p>
                        <h2
                            id="create-heading"
                            className="mt-4 text-2xl font-medium tracking-tight"
                        >
                            Make room for a helpful mind.
                        </h2>
                        <p className="mt-3 leading-7 text-muted">
                            Give your agent a name and a place to work. Your conversation and
                            activity will stay here, ready when you return.
                        </p>
                        {occupied ? (
                            <p className="mt-6 rounded-xl bg-sage p-4">
                                This personal workshop already has an agent owned by another local
                                account.
                            </p>
                        ) : (
                            <form
                                className="mt-7 grid gap-5"
                                onSubmit={async (event) => {
                                    event.preventDefault();
                                    if (submitting.current) return;
                                    submitting.current = true;
                                    setBusy('create');
                                    setError('');
                                    try {
                                        const created = await post<WorkshopState>(createAgentUrl, {
                                            name,
                                            workspace,
                                        });
                                        cursor.current = created.cursor;
                                        setState(created);
                                    } catch (failure) {
                                        setError(
                                            failure instanceof Error
                                                ? failure.message
                                                : 'Could not create the agent.',
                                        );
                                    } finally {
                                        submitting.current = false;
                                        setBusy(null);
                                    }
                                }}
                            >
                                <label className="grid gap-2 text-sm font-medium">
                                    Agent name
                                    <input
                                        required
                                        maxLength={80}
                                        value={name}
                                        onChange={(event) => setName(event.target.value)}
                                        placeholder="For example, Sage"
                                        className="rounded-xl border border-line bg-cream px-4 py-3"
                                    />
                                </label>
                                <label className="grid gap-2 text-sm font-medium">
                                    Working directory
                                    <select
                                        required
                                        value={workspace}
                                        onChange={(event) => setWorkspace(event.target.value)}
                                        className="min-w-0 rounded-xl border border-line bg-cream px-4 py-3"
                                    >
                                        <option value="" disabled>
                                            Choose a workspace
                                        </option>
                                        {workspaces.map((item) => (
                                            <option
                                                key={item.key}
                                                value={item.key}
                                                disabled={!item.available}
                                            >
                                                {item.name}
                                                {!item.available ? ' — unavailable' : ''}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <p className="text-sm break-all text-muted">
                                    {workspaces.find((item) => item.key === workspace)?.path ??
                                        'No workspace is available. Ask the workshop owner to prepare one.'}
                                </p>
                                <button
                                    disabled={!!busy || !workspace}
                                    className={`${button} justify-self-start`}
                                >
                                    {busy === 'create' ? 'Creating…' : 'Create agent'}
                                </button>
                            </form>
                        )}
                    </div>
                </section>
            ) : (
                <div className="mt-9 grid items-start gap-6 md:grid-cols-[14rem_minmax(0,1fr)]">
                    <aside className="grid gap-4">
                        <button
                            onClick={() => setOpen((current) => !current)}
                            aria-expanded={open}
                            aria-controls="agent-panel"
                            className="w-full rounded-3xl border border-line bg-sage/70 p-6 text-left hover:bg-sage"
                        >
                            <span
                                aria-hidden="true"
                                className="mb-5 grid size-11 place-items-center rounded-2xl bg-forest text-xl text-cream"
                            >
                                {agent.name.slice(0, 1).toUpperCase()}
                            </span>
                            <span className="block text-lg font-semibold">{agent.name}</span>
                            <span className="mt-2 block text-sm text-muted">
                                {labels[agent.status] ?? 'Checking status'}
                            </span>
                            <span className="mt-5 block text-sm font-medium text-forest">
                                {open ? 'Close panel' : 'Open conversation'}{' '}
                                <span aria-hidden="true">↗</span>
                            </span>
                        </button>
                        <p className="px-2 text-xs leading-5 break-all text-muted">
                            Working in
                            <br />
                            {agent.workspace}
                        </p>
                        <p className="px-2 text-xs leading-5 text-muted">
                            Your agent keeps working when you close this tab.
                        </p>
                    </aside>
                    {open && (
                        <section
                            id="agent-panel"
                            aria-label={`${agent.name} conversation and activity`}
                            className="min-w-0 overflow-hidden rounded-3xl border border-line bg-white/60"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-5">
                                <div>
                                    <h2 className="font-semibold">With {agent.name}</h2>
                                    <p role="status" className="mt-1 text-sm text-muted">
                                        {labels[agent.status] ?? 'Checking status'}
                                    </p>
                                </div>
                                <button
                                    disabled={!canStop || !!busy}
                                    onClick={() =>
                                        void act('stop', agent.urls.stop, {
                                            turn_id: agent.active_turn_id,
                                        })
                                    }
                                    className={secondary}
                                >
                                    {agent.status === 'stopping' ? 'Stopping…' : 'Stop task'}
                                </button>
                            </div>
                            {agent.notice && (
                                <div className="border-b border-line bg-sage/40 px-5 py-4">
                                    <p role="status" className="text-sm leading-6">
                                        {agent.notice}
                                    </p>
                                    {agent.can_recover && (
                                        <button
                                            disabled={!!busy}
                                            onClick={() =>
                                                void act('recover', agent.urls.recover, {})
                                            }
                                            className={`${secondary} mt-3`}
                                        >
                                            Resume conversation
                                        </button>
                                    )}
                                </div>
                            )}
                            {pending.length > 0 && (
                                <div className="grid gap-4 border-b border-line bg-amber-50 p-5">
                                    {pending.map((approval) => (
                                        <section
                                            key={approval.id}
                                            aria-label="Permission request"
                                            className="min-w-0"
                                        >
                                            <h3 className="font-semibold">
                                                Your permission is needed
                                            </h3>
                                            <p className="mt-2 text-sm">{approval.action}</p>
                                            {approval.command && (
                                                <pre className="mt-3 max-h-44 overflow-auto rounded-xl bg-white/70 p-3 text-sm whitespace-pre-wrap break-all">
                                                    {approval.command}
                                                </pre>
                                            )}
                                            <p className="mt-3 text-xs break-all text-muted">
                                                Working directory: {approval.cwd}
                                            </p>
                                            {approval.reason && (
                                                <p className="mt-3 text-sm leading-6">
                                                    {approval.reason}
                                                </p>
                                            )}
                                            {approval.permissions && (
                                                <pre className="mt-3 overflow-auto text-xs whitespace-pre-wrap break-all">
                                                    {approval.permissions}
                                                </pre>
                                            )}
                                            <p className="mt-3 text-xs text-muted">
                                                {approval.permissions
                                                    ? 'Requested access lasts only until this task ends.'
                                                    : 'Approval applies to this action only. No lasting permission is granted.'}
                                            </p>
                                            <div className="mt-4 flex flex-wrap gap-3">
                                                <button
                                                    disabled={
                                                        !!busy ||
                                                        !agent.online ||
                                                        approval.status !== 'pending' ||
                                                        agent.status === 'stopping'
                                                    }
                                                    className={button}
                                                    onClick={() =>
                                                        void act(
                                                            `allow-${approval.id}`,
                                                            agent.urls.decisions,
                                                            {
                                                                turn_id: approval.turn_id,
                                                                approval_id: approval.id,
                                                                decision: 'allow',
                                                            },
                                                        )
                                                    }
                                                >
                                                    Approve once
                                                </button>
                                                <button
                                                    disabled={
                                                        !!busy ||
                                                        !agent.online ||
                                                        approval.status !== 'pending' ||
                                                        agent.status === 'stopping'
                                                    }
                                                    className={secondary}
                                                    onClick={() =>
                                                        void act(
                                                            `deny-${approval.id}`,
                                                            agent.urls.decisions,
                                                            {
                                                                turn_id: approval.turn_id,
                                                                approval_id: approval.id,
                                                                decision: 'deny',
                                                            },
                                                        )
                                                    }
                                                >
                                                    Deny
                                                </button>
                                                {approval.status === 'deciding' && (
                                                    <span
                                                        role="status"
                                                        className="self-center text-sm"
                                                    >
                                                        Sending your decision…
                                                    </span>
                                                )}
                                            </div>
                                        </section>
                                    ))}
                                </div>
                            )}
                            <div
                                role="tablist"
                                aria-label="Agent panel"
                                className="flex gap-2 border-b border-line px-5 pt-4"
                            >
                                {(['conversation', 'activity'] as const).map((tab) => (
                                    <button
                                        key={tab}
                                        id={`${tab}-tab`}
                                        role="tab"
                                        aria-selected={panel === tab}
                                        aria-controls={`${tab}-panel`}
                                        tabIndex={panel === tab ? 0 : -1}
                                        onClick={() => setPanel(tab)}
                                        onKeyDown={(event) => {
                                            if (
                                                event.key === 'ArrowLeft' ||
                                                event.key === 'ArrowRight'
                                            ) {
                                                event.preventDefault();
                                                const next =
                                                    tab === 'conversation'
                                                        ? 'activity'
                                                        : 'conversation';
                                                setPanel(next);
                                                document.getElementById(`${next}-tab`)?.focus();
                                            }
                                        }}
                                        className={`rounded-t-xl px-4 py-3 text-sm font-medium ${panel === tab ? 'bg-sage text-forest' : 'text-muted hover:bg-sage/50'}`}
                                    >
                                        {tab === 'conversation' ? 'Conversation' : 'Activity'}
                                    </button>
                                ))}
                            </div>
                            <div
                                id={`${panel}-panel`}
                                role="tabpanel"
                                aria-labelledby={`${panel}-tab`}
                                tabIndex={0}
                                className="h-[26rem] overflow-y-auto p-5 sm:p-6"
                            >
                                {panel === 'conversation' ? (
                                    <div className="grid gap-5">
                                        {state?.messages.length === 0 && (
                                            <p className="py-14 text-center text-sm leading-7 text-muted">
                                                A fresh page, a helpful partner.
                                                <br />
                                                Send your first task when your agent is ready.
                                            </p>
                                        )}
                                        {state?.messages.map((message) => (
                                            <article
                                                key={message.id}
                                                aria-label={
                                                    message.role === 'user'
                                                        ? 'Your message'
                                                        : `${agent.name} message`
                                                }
                                                className={`min-w-0 max-w-[95%] rounded-2xl p-4 ${message.role === 'user' ? 'justify-self-end bg-sage' : 'justify-self-start border border-line bg-white/70'}`}
                                            >
                                                <p className="mb-2 text-xs font-semibold text-muted">
                                                    {message.role === 'user'
                                                        ? message.kind === 'steer'
                                                            ? 'You · steering'
                                                            : 'You'
                                                        : agent.name}
                                                </p>
                                                <p className="text-sm leading-7 whitespace-pre-wrap break-words [overflow-wrap:anywhere]">
                                                    {message.body || 'Thinking…'}
                                                </p>
                                            </article>
                                        ))}
                                        <div ref={end} />
                                    </div>
                                ) : (
                                    <ol className="grid gap-4">
                                        {state?.activity.length === 0 && (
                                            <li className="py-14 text-center text-sm text-muted">
                                                Tool activity will appear here as your agent works.
                                            </li>
                                        )}
                                        {state?.activity.map((activity) => (
                                            <li
                                                key={activity.id}
                                                className="min-w-0 rounded-2xl border border-line p-4"
                                            >
                                                <div className="flex flex-wrap justify-between gap-2">
                                                    <span className="text-sm font-medium">
                                                        {activity.label}
                                                    </span>
                                                    <span className="text-xs text-muted">
                                                        {activity.phase === 'running'
                                                            ? 'In progress'
                                                            : activity.phase === 'output'
                                                              ? 'Output'
                                                              : activity.phase === 'declined'
                                                                ? 'Denied'
                                                                : activity.phase === 'failed'
                                                                  ? 'Failed'
                                                                  : 'Finished'}
                                                    </span>
                                                </div>
                                                {activity.detail && (
                                                    <pre className="mt-3 max-h-48 overflow-auto text-xs leading-6 whitespace-pre-wrap break-all text-muted">
                                                        {activity.detail}
                                                    </pre>
                                                )}
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </div>
                            <div className="grid gap-4 border-t border-line bg-cream/70 p-5">
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        void act('task', agent.urls.tasks, { text }, () =>
                                            setText(''),
                                        );
                                    }}
                                    className="grid gap-3"
                                >
                                    <label htmlFor="task" className="text-sm font-medium">
                                        {state?.messages.length
                                            ? 'Next task or follow-up'
                                            : 'Your first task'}
                                    </label>
                                    <textarea
                                        id="task"
                                        rows={3}
                                        maxLength={16000}
                                        required
                                        value={text}
                                        onChange={(event) => setText(event.target.value)}
                                        disabled={!agent.online || agent.status !== 'idle'}
                                        placeholder={
                                            active
                                                ? 'A task is running. Use steering below to change direction.'
                                                : 'What would you like to work on?'
                                        }
                                        className="min-w-0 resize-y rounded-xl border border-line bg-white p-3 text-sm leading-6 disabled:bg-sage/30"
                                    />
                                    <button
                                        disabled={
                                            !!busy ||
                                            !agent.online ||
                                            agent.status !== 'idle' ||
                                            !text.trim()
                                        }
                                        className={`${button} justify-self-end`}
                                    >
                                        {busy === 'task' ? 'Sending…' : 'Send task'}
                                    </button>
                                </form>
                                {active && (
                                    <form
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            void act(
                                                'steer',
                                                agent.urls.steer,
                                                { text: steering, turn_id: agent.active_turn_id },
                                                () => setSteering(''),
                                            );
                                        }}
                                        className="grid gap-3 border-t border-line pt-4"
                                    >
                                        <label htmlFor="steering" className="text-sm font-medium">
                                            Steer the running task
                                        </label>
                                        <p className="text-xs leading-5 text-muted">
                                            Add direction to the current task. This does not start
                                            another task.
                                        </p>
                                        <textarea
                                            id="steering"
                                            rows={2}
                                            maxLength={16000}
                                            required
                                            value={steering}
                                            onChange={(event) => setSteering(event.target.value)}
                                            disabled={agent.status !== 'running' || !agent.online}
                                            className="min-w-0 resize-y rounded-xl border border-line bg-white p-3 text-sm disabled:bg-sage/30"
                                        />
                                        <button
                                            disabled={
                                                !!busy ||
                                                agent.status !== 'running' ||
                                                !agent.online ||
                                                !steering.trim()
                                            }
                                            className={`${secondary} justify-self-end`}
                                        >
                                            Send steering
                                        </button>
                                    </form>
                                )}
                                <p className="text-xs text-muted">{connection}</p>
                            </div>
                        </section>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
