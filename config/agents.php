<?php

return [
    'allowed_project_roots' => explode(PATH_SEPARATOR, env('PROJECT_ALLOWED_ROOTS', '/home/wesley/projects')),
    'workspaces' => [
        'sandbox' => [
            'name' => 'Disposable sandbox',
            'path' => env('AGENT_WORKSPACE_PATH', sys_get_temp_dir().'/ai-agents-workshop-'.getmyuid()),
        ],
    ],
    'heartbeat_seconds' => 15,
    'worker_lock' => storage_path('framework/agent-worker.lock'),
    'stream_seconds' => 10,
    'request_timeout' => 20,
    'turn_timeout' => 1800,
];
