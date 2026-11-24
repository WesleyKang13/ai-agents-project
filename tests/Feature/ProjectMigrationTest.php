<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectMigrationTest extends TestCase
{
    public function test_upgrade_preserves_existing_owners_workspaces_threads_and_all_conversation_records(): void
    {
        $connection = DB::getDefaultConnection();
        config(['database.connections.legacy_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('legacy_upgrade');

        try {
            (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
            (require database_path('migrations/2026_11_21_161255_create_agent_workshop_tables.php'))->up();
            $owner = User::factory()->create();
            $other = User::factory()->create();
            $agentId = DB::table('agents')->insertGetId(['user_id' => $owner->id, 'name' => 'Sage', 'workspace_key' => 'sandbox', 'workspace_path' => sys_get_temp_dir(), 'thread_id' => 'preserve-thread', 'status' => 'idle']);
            DB::table('agents')->insert(['slot' => 2, 'user_id' => $other->id, 'name' => 'Other', 'workspace_key' => 'sandbox', 'workspace_path' => sys_get_temp_dir(), 'thread_id' => 'other-thread', 'status' => 'offline']);
            $turnId = DB::table('agent_turns')->insertGetId(['agent_id' => $agentId, 'codex_id' => 'preserve-turn', 'status' => 'completed']);
            DB::table('agent_messages')->insert(['agent_id' => $agentId, 'agent_turn_id' => $turnId, 'item_id' => 'preserve-message', 'role' => 'assistant', 'kind' => 'task', 'body' => 'Existing Sage conversation']);
            DB::table('agent_events')->insert(['agent_id' => $agentId, 'type' => 'activity', 'data' => '{"label":"Existing activity"}']);
            DB::table('agent_controls')->insert(['agent_id' => $agentId, 'agent_turn_id' => $turnId, 'request_key' => '12345678-1234-4234-8234-123456789012', 'kind' => 'task', 'fingerprint' => str_repeat('a', 64), 'payload' => '{"text":"Hello"}', 'status' => 'completed']);
            DB::table('agent_approvals')->insert(['agent_id' => $agentId, 'agent_turn_id' => $turnId, 'connection_id' => '12345678-1234-4234-8234-123456789012', 'request_key' => '0', 'request_payload' => '{}', 'context' => '{}', 'status' => 'denied']);
            $tables = ['users', 'agents', 'agent_turns', 'agent_messages', 'agent_events', 'agent_controls', 'agent_approvals'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $record): array => (array) $record)->all();
            }

            (require database_path('migrations/2026_11_24_223541_add_projects_and_project_owned_agents.php'))->up();

            foreach ($tables as $table) {
                $columns = array_keys($before[$table][0]);
                $after = DB::table($table)->select($columns)->orderBy('id')->get()->map(fn (object $record): array => (array) $record)->all();
                $this->assertSame($before[$table], $after, 'Preserve all original '.$table.' data.');
            }
            $projects = DB::table('projects')->orderBy('id')->get();
            $this->assertCount(2, $projects);
            $this->assertSame($owner->id, $projects[0]->user_id);
            $this->assertSame($other->id, $projects[1]->user_id);
            $this->assertStringStartsWith('Legacy workspace', $projects[0]->name);
            $this->assertSame(1, $projects[0]->is_legacy);
            $this->assertSame(1, $projects[0]->is_temporary);
            $this->assertSame($projects[0]->id, DB::table('agents')->where('id', $agentId)->value('project_id'));
            DB::table('agents')->insert(['user_id' => $owner->id, 'project_id' => $projects[0]->id, 'name' => 'New saved agent', 'workspace_key' => 'project', 'workspace_path' => sys_get_temp_dir()]);
            $this->assertSame(3, DB::table('agents')->count());
        } finally {
            DB::purge('legacy_upgrade');
            DB::setDefaultConnection($connection);
        }
    }
}
