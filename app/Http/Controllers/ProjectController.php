<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectFolders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Projects', [
            'projects' => $request->user()->projects()->withCount('agents')->orderBy('name')->orderBy('id')->get()->map(fn (Project $project): array => [...$project->summary(), 'agent_count' => $project->agents_count]),
            'createProjectUrl' => route('projects.store', absolute: false),
            'browseFoldersUrl' => route('projects.folders', absolute: false),
        ]);
    }

    public function store(Request $request, ProjectFolders $folders): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'folder' => ['required', 'string', 'max:4096']]);
        $path = $folders->resolve($data['folder']);

        try {
            $project = $request->user()->projects()->create(['name' => $data['name'], 'workspace_path' => $path, 'workspace_hash' => hash('sha256', $path)]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['folder' => 'You have already registered this folder. Open it from Projects.']);
        }

        return redirect()->route('projects.workshop', $project);
    }
}
