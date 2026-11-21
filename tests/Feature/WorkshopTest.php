<?php

namespace Tests\Feature;

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

    public function test_home_provides_navigation_to_the_workshop(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('projectName', 'AI Agents Project')
                ->where('navigation.workshop', '/workshop')
            );
    }

    public function test_workshop_can_be_loaded_directly_and_refreshed(): void
    {
        foreach (range(1, 2) as $visit) {
            $this->get('/workshop')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Workshop')
                    ->where('projectName', 'AI Agents Project')
                    ->where('navigation.home', '/')
                    ->where('workshop', null)
                    ->where('milestone', 'One agent controlled from the browser')
                );
        }
    }

    public function test_inertia_navigation_receives_workshop_data_from_laravel(): void
    {
        $version = $this->get('/workshop')->viewData('page')['version'];

        $this->get('/workshop', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version ?? '',
        ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Workshop')
            ->assertJsonPath('props.workshop', null)
            ->assertJsonPath('props.milestone', 'One agent controlled from the browser')
            ->assertJsonPath('url', '/workshop');
    }
}
