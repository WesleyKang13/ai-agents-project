<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentTurn;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentTurn> */
class AgentTurnFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['agent_id' => Agent::factory(), 'codex_id' => null, 'status' => 'queued'];
    }
}
