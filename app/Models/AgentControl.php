<?php

namespace App\Models;

use Database\Factories\AgentControlFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['agent_id', 'agent_turn_id', 'request_key', 'kind', 'fingerprint', 'payload', 'status'])]
#[Hidden(['payload', 'fingerprint', 'request_key'])]
class AgentControl extends Model
{
    /** @use HasFactory<AgentControlFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }
}
