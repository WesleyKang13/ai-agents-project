<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentMessage> */
class AgentMessageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['agent_id' => Agent::factory(), 'agent_turn_id' => null, 'role' => 'user', 'kind' => 'task', 'body' => 'A small task'];
    }
}
