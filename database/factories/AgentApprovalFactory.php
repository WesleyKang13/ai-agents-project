<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentApproval;
use App\Models\AgentTurn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentApproval> */
class AgentApprovalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['agent_id' => Agent::factory(), 'agent_turn_id' => fn (array $attributes) => AgentTurn::factory()->create(['agent_id' => $attributes['agent_id']])->id, 'connection_id' => (string) Str::uuid(), 'request_key' => '0', 'request_payload' => ['id' => 0, 'method' => 'item/commandExecution/requestApproval', 'params' => []], 'context' => ['action' => 'Run command', 'command' => 'echo test', 'cwd' => sys_get_temp_dir(), 'reason' => '', 'permissions' => ''], 'status' => 'pending'];
    }
}
