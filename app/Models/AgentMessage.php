<?php

namespace App\Models;

use Database\Factories\AgentMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['agent_id', 'agent_turn_id', 'item_id', 'role', 'kind', 'body'])]
#[Hidden(['item_id'])]
class AgentMessage extends Model
{
    /** @use HasFactory<AgentMessageFactory> */
    use HasFactory;
}
