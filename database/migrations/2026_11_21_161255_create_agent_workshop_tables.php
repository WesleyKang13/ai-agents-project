<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('slot')->default(1)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('workspace_key', 80);
            $table->text('workspace_path');
            $table->string('thread_id')->nullable();
            $table->string('status')->default('offline');
            $table->text('notice')->nullable();
            $table->unsignedBigInteger('active_turn_id')->nullable();
            $table->uuid('connection_id')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedBigInteger('server_pid')->nullable();
            $table->string('server_start')->nullable();
            $table->timestamps();
        });
        Schema::create('agent_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('codex_id')->nullable();
            $table->string('status')->default('queued');
            $table->timestamps();
            $table->unique(['agent_id', 'codex_id']);
        });
        Schema::create('agent_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_turn_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('item_id')->nullable();
            $table->string('role', 20);
            $table->string('kind', 20)->default('task');
            $table->text('body');
            $table->timestamps();
            $table->unique(['agent_id', 'item_id']);
        });
        Schema::create('agent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('data');
            $table->timestamps();
            $table->index(['agent_id', 'id']);
        });
        Schema::create('agent_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_turn_id')->constrained()->cascadeOnDelete();
            $table->uuid('connection_id');
            $table->string('request_key');
            $table->text('request_payload');
            $table->json('context');
            $table->string('status')->default('pending');
            $table->string('decision')->nullable();
            $table->timestamps();
            $table->unique(['agent_id', 'connection_id', 'request_key']);
        });
        Schema::create('agent_controls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_turn_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->string('kind', 30);
            $table->string('fingerprint', 64);
            $table->text('payload');
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['agent_id', 'request_key']);
            $table->index(['agent_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['agent_controls', 'agent_approvals', 'agent_events', 'agent_messages', 'agent_turns', 'agents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
