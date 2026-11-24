<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['project_id', 'user_id', 'name', 'role', 'instructions', 'workspace_key', 'workspace_path', 'thread_id', 'status', 'notice', 'active_turn_id', 'connection_id', 'heartbeat_at', 'reconciled_at', 'server_pid', 'server_start'])]
#[Hidden(['workspace_path', 'thread_id', 'connection_id', 'server_pid', 'server_start'])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Agent $agent): void {
            if ($agent->exists && $agent->isDirty(['project_id', 'user_id', 'workspace_key', 'workspace_path'])) {
                throw ValidationException::withMessages(['project' => 'An agent stays in its original project and workspace.']);
            }

            if (! $agent->exists && ! Project::query()->whereKey($agent->project_id)->where('user_id', $agent->user_id)->where('workspace_path', $agent->workspace_path)->exists()) {
                throw ValidationException::withMessages(['project' => 'The agent must use its owner’s project workspace.']);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function ownedBy(User $user): bool
    {
        return $this->user_id === $user->id && $this->project()->where('user_id', $user->id)->exists();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['heartbeat_at' => 'datetime', 'reconciled_at' => 'datetime', 'active_turn_id' => 'integer'];
    }
}
