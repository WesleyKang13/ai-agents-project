export type SharedProps = {
    projectName: string;
    navigation: { home: string; workshop: string };
    auth: { user: { name: string } | null };
    logoutUrl: string;
};

export type WorkshopAgent = {
    id: number;
    name: string;
    workspace: string;
    status: string;
    online: boolean;
    active_turn_id: number | null;
    notice: string | null;
    can_recover: boolean;
    urls: Record<'state' | 'events' | 'tasks' | 'steer' | 'stop' | 'decisions' | 'recover', string>;
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
