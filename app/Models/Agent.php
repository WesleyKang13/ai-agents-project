<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slot', 'user_id', 'name', 'workspace_key', 'workspace_path', 'thread_id', 'status', 'notice', 'active_turn_id', 'connection_id', 'heartbeat_at', 'reconciled_at', 'server_pid', 'server_start'])]
#[Hidden(['workspace_path', 'thread_id', 'connection_id', 'server_pid', 'server_start'])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['heartbeat_at' => 'datetime', 'reconciled_at' => 'datetime', 'active_turn_id' => 'integer'];
    }
}
