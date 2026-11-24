<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkshopTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_home_provides_navigation_to_projects(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('projectName', 'AI Agents Project')
                ->where('navigation.projects', '/projects')
            );
    }

    public function test_workshop_can_be_loaded_directly_and_refreshed(): void
    {
        $project = Project::factory()->create(['user_id' => auth()->id()]);

        foreach (range(1, 2) as $visit) {
            $this->get('/projects/'.$project->id.'/workshop')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Workshop')
                    ->where('projectName', 'AI Agents Project')
                    ->where('navigation.home', '/')
                    ->where('workshop', null)
                    ->has('agents', 0)
                    ->where('project.id', $project->id)
                );
        }
    }

    public function test_inertia_navigation_receives_workshop_data_from_laravel(): void
    {
        $project = Project::factory()->create(['user_id' => auth()->id()]);
        $version = $this->get('/projects/'.$project->id.'/workshop')->viewData('page')['version'];

        $this->get('/projects/'.$project->id.'/workshop', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version ?? '',
        ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Workshop')
            ->assertJsonPath('props.workshop', null)
            ->assertJsonPath('props.project.id', $project->id)
            ->assertJsonPath('url', '/projects/'.$project->id.'/workshop');
    }
}
