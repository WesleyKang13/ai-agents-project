<?php

namespace App\Models;

use Database\Factories\AgentTurnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['agent_id', 'codex_id', 'status'])]
#[Hidden(['codex_id'])]
class AgentTurn extends Model
{
    /** @use HasFactory<AgentTurnFactory> */
    use HasFactory;
}
