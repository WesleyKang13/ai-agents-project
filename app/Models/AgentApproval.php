<?php

namespace App\Models;

use Database\Factories\AgentApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['agent_id', 'agent_turn_id', 'connection_id', 'request_key', 'request_payload', 'context', 'status', 'decision'])]
#[Hidden(['request_payload', 'request_key', 'connection_id'])]
class AgentApproval extends Model
{
    /** @use HasFactory<AgentApprovalFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['request_payload' => 'encrypted:array', 'context' => 'array'];
    }
}
