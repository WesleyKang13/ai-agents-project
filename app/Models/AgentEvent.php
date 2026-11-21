<?php

namespace App\Models;

use Database\Factories\AgentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['agent_id', 'type', 'data'])]
class AgentEvent extends Model
{
    /** @use HasFactory<AgentEventFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
