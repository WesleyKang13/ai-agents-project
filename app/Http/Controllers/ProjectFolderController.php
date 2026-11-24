<?php

namespace App\Http\Controllers;

use App\Services\ProjectFolders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectFolderController extends Controller
{
    public function __invoke(Request $request, ProjectFolders $folders): JsonResponse
    {
        $data = $request->validate(['path' => ['sometimes', 'required', 'string', 'max:4096']]);

        return response()->json($folders->browse($data['path'] ?? null))->header('Cache-Control', 'no-store');
    }
}
