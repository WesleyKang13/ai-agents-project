<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentEvent> */
class AgentEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['agent_id' => Agent::factory(), 'type' => 'activity', 'data' => ['label' => 'Working', 'detail' => '', 'phase' => 'running']];
    }
}
