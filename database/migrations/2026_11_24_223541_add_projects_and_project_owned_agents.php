<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->text('workspace_path');
            $table->string('workspace_hash', 64);
            $table->boolean('is_legacy')->default(false);
            $table->boolean('is_temporary')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'workspace_hash']);
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 120)->nullable();
            $table->text('instructions')->nullable();
            $table->dropUnique(['slot']);
        });

        DB::table('agents')->orderBy('id')->chunkById(100, function (Collection $agents): void {
            foreach ($agents as $agent) {
                $path = realpath($agent->workspace_path) ?: $agent->workspace_path;
                $hash = hash('sha256', $path);
                $projectId = DB::table('projects')->where('user_id', $agent->user_id)->where('workspace_hash', $hash)->value('id');

                if ($projectId === null) {
                    $projectId = DB::table('projects')->insertGetId([
                        'user_id' => $agent->user_id,
                        'name' => mb_substr('Legacy workspace — '.basename($path), 0, 120),
                        'workspace_path' => $path, 'workspace_hash' => $hash,
                        'is_legacy' => true,
                        'is_temporary' => $agent->workspace_key === 'sandbox' || str_starts_with($path, '/tmp/'),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                DB::table('agents')->where('id', $agent->id)->update(['project_id' => $projectId]);
            }
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('This data-preserving migration is forward-only. Keep registered projects and conversations; use a forward migration to change the schema.');
    }
};
