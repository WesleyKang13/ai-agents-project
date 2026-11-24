<?php

namespace App\Services;

use DirectoryIterator;
use Illuminate\Validation\ValidationException;

class ProjectFolders
{
    /** @return list<string> */
    public function roots(): array
    {
        $roots = [];

        foreach (config('agents.allowed_project_roots', []) as $root) {
            $canonical = realpath($root);

            if ($canonical !== false && $this->accessible($canonical)) {
                $roots[] = $canonical;
            }
        }

        return array_values(array_unique($roots));
    }

    public function resolve(string $path): string
    {
        $canonical = $this->canonical($path);

        foreach ($this->roots() as $root) {
            if ($canonical === $root || str_starts_with($canonical, rtrim($root, '/').'/')) {
                return $canonical;
            }
        }

        throw ValidationException::withMessages(['folder' => 'Choose an existing accessible folder within an allowed project root.']);
    }

    public function canonical(string $path): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw ValidationException::withMessages(['folder' => 'Use an absolute folder path without traversal.']);
        }

        clearstatcache(true);
        $canonical = realpath($path);

        if ($canonical === false || ! $this->accessible($canonical)) {
            throw ValidationException::withMessages(['folder' => 'The folder must exist and allow reading, writing and directory access.']);
        }

        return $canonical;
    }

    private function accessible(string $path): bool
    {
        return is_dir($path) && is_readable($path) && is_writable($path) && is_executable($path);
    }

    /** @return array{roots: list<string>, path: ?string, parent: ?string, directories: list<array{name: string, path: string}>} */
    public function browse(?string $path): array
    {
        $roots = $this->roots();
        $directories = [];
        $canonical = $path === null ? null : $this->resolve($path);
        $parent = null;

        if ($canonical !== null) {
            if (! in_array($canonical, $roots, true)) {
                $parent = dirname($canonical);
            }

            foreach (new DirectoryIterator($canonical) as $entry) {
                if ($entry->isDot() || ! $entry->isDir()) {
                    continue;
                }

                try {
                    $child = $this->resolve($entry->getPathname());
                    $directories[] = ['name' => $entry->getFilename(), 'path' => $child];
                } catch (ValidationException) {
                    continue;
                }
            }

            usort($directories, fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        }

        return ['roots' => $roots, 'path' => $canonical, 'parent' => $parent, 'directories' => $directories];
    }
}
