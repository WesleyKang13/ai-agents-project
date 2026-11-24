<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentApproval;
use App\Models\AgentEvent;
use App\Models\AgentMessage;
use App\Models\AgentTurn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProjectFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $fixture;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir().'/project-folders-test-'.bin2hex(random_bytes(8));
        $this->root = $this->fixture.'/allowed';
        foreach (['allowed/project/nested', 'outside', 'allowed-sibling', 'allowed/locked'] as $directory) {
            File::ensureDirectoryExists($this->fixture.'/'.$directory, 0700);
        }
        file_put_contents($this->root.'/project/existing.txt', 'Keep these files.');
        symlink($this->fixture.'/outside', $this->root.'/escape');
        symlink($this->root.'/project', $this->root.'/alias');
        config(['agents.allowed_project_roots' => [$this->root], 'agents.stream_seconds' => 0]);
    }

    protected function tearDown(): void
    {
        chmod($this->root.'/locked', 0700);
        File::deleteDirectory($this->fixture);
        parent::tearDown();
    }

    public function test_lists_only_owned_projects_with_folder_and_agent_count(): void
    {
        $user = User::factory()->create();
        $own = Project::factory()->create(['user_id' => $user->id, 'name' => 'My project']);
        Agent::factory()->inProject($own)->create();
        Project::factory()->create(['name' => 'Private other project']);

        $this->actingAs($user)->get('/projects')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Projects')->has('projects', 1)->where('projects.0.id', $own->id)
            ->where('projects.0.folder', sys_get_temp_dir())->where('projects.0.agent_count', 1));
    }

    public function test_registers_existing_non_git_folder_with_zero_agents_and_preserves_files(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', ['name' => 'Real project', 'folder' => $this->root.'/project', 'user_id' => 999, 'is_legacy' => true])->assertRedirect();

        $project = Project::query()->sole();
        $this->assertSame($user->id, $project->user_id);
        $this->assertSame($this->root.'/project', $project->workspace_path);
        $this->assertFalse($project->is_legacy);
        $this->assertDatabaseCount('agents', 0);
        $this->assertFileDoesNotExist($project->workspace_path.'/.git');
        $this->assertSame('Keep these files.', file_get_contents($project->workspace_path.'/existing.txt'));
        $this->get($project->summary()['workshop_url'])->assertOk()->assertInertia(fn (Assert $page) => $page->component('Workshop')->has('agents', 0)->where('workshop', null));
    }

    public function test_duplicate_canonical_folder_is_rejected_for_same_owner_but_allowed_for_another(): void
    {
        $first = User::factory()->create();
        $this->actingAs($first)->post('/projects', ['name' => 'First', 'folder' => $this->root.'/project'])->assertRedirect();

        $this->postJson('/projects', ['name' => 'Alias', 'folder' => $this->root.'/alias/.'])->assertUnprocessable()->assertJsonValidationErrors('folder')->assertJsonPath('errors.folder.0', 'You have already registered this folder. Open it from Projects.');
        $this->actingAs(User::factory()->create())->post('/projects', ['name' => 'Another owner', 'folder' => $this->root.'/project'])->assertRedirect();
        $this->assertDatabaseCount('projects', 2);
        $this->assertDatabaseCount('agents', 0);
    }

    public function test_folder_browser_lists_only_one_level_and_filters_symlink_escapes(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/projects/folders')->assertOk()->assertExactJson(['roots' => [$this->root], 'path' => null, 'parent' => null, 'directories' => []]);

        $listing = $this->getJson('/projects/folders?'.http_build_query(['path' => $this->root]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('parent', null);
        $this->assertSame(['alias', 'locked', 'project'], array_column($listing->json('directories'), 'name'));
        $this->assertStringNotContainsString('nested', $listing->getContent());
        $this->getJson('/projects/folders?'.http_build_query(['path' => $this->root.'/project']))->assertOk()->assertJsonPath('parent', $this->root)->assertJsonPath('directories.0.name', 'nested');
    }

    #[TestWith(['relative'])]
    #[TestWith(['traversal'])]
    #[TestWith(['missing'])]
    #[TestWith(['file'])]
    #[TestWith(['outside'])]
    #[TestWith(['prefix'])]
    #[TestWith(['symlink'])]
    #[TestWith(['null-byte'])]
    #[TestWith(['permissions'])]
    public function test_rejects_invalid_folder_for_browsing_and_registration(string $case): void
    {
        $path = match ($case) {
            'relative' => 'project',
            'traversal' => $this->root.'/project/../project',
            'missing' => $this->root.'/missing',
            'file' => $this->root.'/project/existing.txt',
            'outside' => $this->fixture.'/outside',
            'prefix' => $this->fixture.'/allowed-sibling',
            'symlink' => $this->root.'/escape',
            'null-byte' => $this->root."/project\0ignored",
            'permissions' => $this->root.'/locked',
        };
        if ($case === 'permissions') {
            chmod($path, 0000);
        }
        $this->actingAs(User::factory()->create());

        $this->getJson('/projects/folders?'.http_build_query(['path' => $path]))->assertUnprocessable()->assertJsonValidationErrors('folder');
        $this->postJson('/projects', ['name' => 'Invalid', 'folder' => $path])->assertUnprocessable()->assertJsonValidationErrors('folder');
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_guests_cannot_list_register_or_browse_projects(): void
    {
        $this->get('/projects')->assertRedirect('/login');
        $this->post('/projects', [])->assertRedirect('/login');
        $this->getJson('/projects/folders')->assertUnauthorized();
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_foreign_project_and_agent_selection_returns_404(): void
    {
        $foreign = Project::factory()->create();
        $agent = Agent::factory()->inProject($foreign)->create();
        $user = User::factory()->create();
        $own = Project::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        $this->get('/projects/'.$foreign->id.'/workshop')->assertNotFound();
        $this->postJson('/projects/'.$foreign->id.'/agents', ['name' => 'Unwanted'])->assertNotFound();
        $this->get('/projects/'.$own->id.'/workshop?agent='.$agent->id)->assertNotFound();
        $this->assertDatabaseCount('agents', 1);
    }

    public function test_agents_in_other_owned_projects_cannot_be_selected_in_this_workshop(): void
    {
        $user = User::factory()->create();
        $first = Project::factory()->create(['user_id' => $user->id]);
        $second = $this->registerProject($user);
        $agent = Agent::factory()->inProject($first)->create();

        $this->actingAs($user)->get('/projects/'.$second->id.'/workshop?agent='.$agent->id)->assertNotFound();
    }

    public function test_ownership_checks_both_agent_and_project_owner(): void
    {
        $agent = Agent::factory()->create();
        $other = User::factory()->create();
        DB::table('agents')->where('id', $agent->id)->update(['user_id' => $other->id]);
        $this->actingAs($other);

        $this->getJson('/workshop/agents/'.$agent->id)->assertNotFound();
        $this->getJson('/workshop/agents/'.$agent->id.'/events')->assertNotFound();
        foreach (['tasks', 'steer', 'stop', 'decisions', 'recover'] as $action) {
            $this->postJson('/workshop/agents/'.$agent->id.'/'.$action, [])->assertNotFound();
        }
        $this->assertDatabaseCount('agent_controls', 0);
    }

    public function test_history_events_controls_and_approvals_stay_with_the_selected_agent(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $first = Agent::factory()->inProject($project)->create(['name' => 'First']);
        $second = Agent::factory()->inProject($project)->create(['name' => 'Second', 'thread_id' => 'second-thread']);
        AgentMessage::factory()->create(['agent_id' => $first->id, 'item_id' => 'same-item', 'body' => 'First private message']);
        AgentMessage::factory()->create(['agent_id' => $second->id, 'item_id' => 'same-item', 'body' => 'Second private message']);
        AgentEvent::factory()->create(['agent_id' => $first->id, 'data' => ['label' => 'First activity']]);
        AgentEvent::factory()->create(['agent_id' => $second->id, 'data' => ['label' => 'Second activity']]);
        $secondTurn = AgentTurn::factory()->create(['agent_id' => $second->id, 'status' => 'running', 'codex_id' => 'second-turn']);
        $second->update(['active_turn_id' => $secondTurn->id, 'status' => 'waiting_permission']);
        $approval = AgentApproval::factory()->create(['agent_id' => $second->id, 'agent_turn_id' => $secondTurn->id, 'connection_id' => $second->connection_id]);
        $this->actingAs($user);

        $this->getJson('/workshop/agents/'.$first->id)->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'First private message')->assertJsonCount(0, 'approvals')->assertDontSee('Second private message');
        $stream = $this->get('/workshop/agents/'.$first->id.'/events')->assertOk()->streamedContent();
        $this->assertStringContainsString('First activity', $stream);
        $this->assertStringNotContainsString('Second activity', $stream);
        $key = (string) Str::uuid();
        $this->postJson('/workshop/agents/'.$first->id.'/tasks', ['request_key' => $key, 'text' => 'First task'])->assertAccepted();
        $this->assertDatabaseHas('agent_controls', ['agent_id' => $first->id, 'request_key' => $key]);
        $this->assertDatabaseMissing('agent_controls', ['agent_id' => $second->id]);
        $firstTurn = $first->fresh()->active_turn_id;
        $first->update(['status' => 'waiting_permission']);
        $this->postJson('/workshop/agents/'.$first->id.'/decisions', ['request_key' => (string) Str::uuid(), 'turn_id' => $firstTurn, 'approval_id' => $approval->id, 'decision' => 'allow'])->assertConflict();
        $this->assertSame('pending', $approval->fresh()->status);
        $this->get('/projects/'.$project->id.'/workshop?agent='.$second->id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('agents', 2)->where('workshop.agent.id', $second->id)->where('workshop.messages.0.body', 'Second private message'));
    }

    public function test_new_work_is_refused_if_the_registered_folder_is_replaced_by_a_symlink(): void
    {
        $user = User::factory()->create();
        $project = $this->registerProject($user);
        $agent = Agent::factory()->inProject($project)->create();
        rename($this->root.'/project', $this->root.'/original');
        symlink($this->fixture.'/outside', $this->root.'/project');

        $this->postJson('/projects/'.$project->id.'/agents', ['name' => 'Other'])->assertUnprocessable()->assertJsonValidationErrors('folder');
        $this->postJson('/workshop/agents/'.$agent->id.'/tasks', ['request_key' => (string) Str::uuid(), 'text' => 'Do not run'])->assertUnprocessable()->assertJsonValidationErrors('folder');
        $this->assertDatabaseCount('agents', 1);
        $this->assertDatabaseCount('agent_controls', 0);
    }

    public function test_an_agent_with_a_conversation_cannot_move_to_another_project(): void
    {
        $user = User::factory()->create();
        $project = $this->registerProject($user);
        $other = Project::factory()->create(['user_id' => $user->id]);
        $agent = Agent::factory()->inProject($project)->create();

        try {
            $agent->update(['project_id' => $other->id, 'workspace_path' => $other->workspace_path]);
            $this->fail('Moving an existing conversation must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project', $exception->errors());
        }
        $this->assertSame($project->id, $agent->fresh()->project_id);
        $this->assertSame('thread-test', $agent->thread_id);
    }

    public function test_project_and_agent_names_and_instructions_are_validated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/projects', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'folder']);
        $project = $this->registerProject($user);
        $this->postJson('/projects/'.$project->id.'/agents', ['name' => ' ', 'role' => str_repeat('x', 121), 'instructions' => str_repeat('x', 16001)])->assertUnprocessable()->assertJsonValidationErrors(['name', 'role', 'instructions']);
        $this->assertDatabaseCount('agents', 0);
    }

    private function registerProject(User $user): Project
    {
        $this->actingAs($user)->post('/projects', ['name' => 'Real project', 'folder' => $this->root.'/project'])->assertRedirect();

        return Project::query()->where('user_id', $user->id)->where('workspace_path', $this->root.'/project')->sole();
    }
}
