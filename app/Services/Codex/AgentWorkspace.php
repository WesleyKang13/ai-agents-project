<?php

namespace App\Services\Codex;

use Illuminate\Validation\ValidationException;

class AgentWorkspace
{
    /** @return list<array{key: string, name: string, path: string, available: bool}> */
    public function choices(): array
    {
        $choices = [];

        foreach (config('agents.workspaces', []) as $key => $workspace) {
            $path = realpath($workspace['path']);
            $choices[] = ['key' => $key, 'name' => $workspace['name'], 'path' => $path ?: $workspace['path'], 'available' => $path !== false && is_dir($path) && is_readable($path) && is_writable($path)];
        }

        return $choices;
    }

    public function resolve(string $key, ?string $expectedPath = null): string
    {
        $workspace = config('agents.workspaces', [])[$key] ?? null;
        $path = $workspace === null ? false : realpath($workspace['path']);

        if ($path === false || ! is_dir($path) || ! is_readable($path) || ! is_writable($path)
            || ($expectedPath !== null && $path !== $expectedPath)) {
            throw ValidationException::withMessages(['workspace' => 'Choose an available workspace configured on this server.']);
        }

        return $path;
    }
}
