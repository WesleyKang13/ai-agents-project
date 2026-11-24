export type SharedProps = {
    projectName: string;
    navigation: { home: string; projects: string };
    auth: { user: { name: string } | null };
    logoutUrl: string;
};

export type WorkshopAgent = {
    id: number;
    name: string;
    workspace: string;
    project_id: number;
    role: string | null;
    instructions: string | null;
    worker_command: string;
    workshop_url: string;
    status: string;
    online: boolean;
    active_turn_id: number | null;
    notice: string | null;
    can_recover: boolean;
    urls: Record<'state' | 'events' | 'tasks' | 'steer' | 'stop' | 'decisions' | 'recover', string>;
};

export type ProjectSummary = {
    id: number;
    name: string;
    folder: string;
    is_legacy: boolean;
    is_temporary: boolean;
    workshop_url: string;
};
export type AgentMessage = {
    id: number;
    turn_id: number | null;
    role: string;
    kind: string;
    body: string;
};
export type AgentApproval = {
    id: number;
    turn_id: number;
    status: string;
    action: string;
    command: string;
    cwd: string;
    reason: string;
    permissions: string;
};
export type AgentActivity = { id: number; label: string; detail: string; phase: string };
export type WorkshopState = {
    agent: WorkshopAgent;
    messages: AgentMessage[];
    turns: { id: number; status: string }[];
    approvals: AgentApproval[];
    activity: AgentActivity[];
    cursor: number;
};
