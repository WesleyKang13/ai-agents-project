<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentControl;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentControl> */
class AgentControlFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['agent_id' => Agent::factory(), 'agent_turn_id' => null, 'request_key' => (string) Str::uuid(), 'kind' => 'task', 'fingerprint' => str_repeat('0', 64), 'payload' => ['text' => 'A small task'], 'status' => 'pending'];
    }
}
