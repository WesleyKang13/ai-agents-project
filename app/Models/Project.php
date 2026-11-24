<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['user_id', 'name', 'workspace_path', 'workspace_hash', 'is_legacy', 'is_temporary'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Project $project): void {
            if ($project->isDirty(['user_id', 'workspace_path', 'workspace_hash', 'is_legacy', 'is_temporary'])) {
                throw ValidationException::withMessages(['project' => 'A registered project cannot change owner or folder.']);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_legacy' => 'boolean', 'is_temporary' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'folder' => $this->workspace_path,
            'is_legacy' => $this->is_legacy, 'is_temporary' => $this->is_temporary,
            'workshop_url' => route('projects.workshop', $this, absolute: false),
        ];
    }
}
