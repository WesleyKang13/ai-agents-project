<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PersonalLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_signs_in_with_a_local_account_and_signs_out(): void
    {
        $user = User::factory()->create();
        $this->withSession(['url.intended' => '/workshop']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/projects');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_rejects_wrong_passwords_and_has_no_public_registration(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/register', ['email' => 'new@example.test', 'password' => 'password'])->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_creates_a_local_account_with_hidden_password_confirmation(): void
    {
        $this->artisan('workshop:user', ['email' => 'owner@example.test'])->expectsQuestion('Name', 'Owner')->expectsQuestion('Password (at least 12 characters)', 'test-only-strong-password')->expectsQuestion('Confirm password', 'test-only-strong-password')->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'owner@example.test']);
        $this->assertNotSame('test-only-strong-password', User::query()->sole()->password);
    }
}
