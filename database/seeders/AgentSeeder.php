<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\User;
use App\Services\Codex\AgentStore;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    public function run(AgentStore $store): void
    {
        if (! Agent::query()->exists()) {
            $store->create(User::query()->sole(), 'Sage', 'sandbox');
        }
    }
}
