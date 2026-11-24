<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'name' => 'Test project',
            'workspace_path' => sys_get_temp_dir(), 'workspace_hash' => hash('sha256', sys_get_temp_dir()),
            'is_legacy' => true, 'is_temporary' => true,
        ];
    }
}
