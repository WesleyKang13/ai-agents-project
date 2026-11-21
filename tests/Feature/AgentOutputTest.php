<?php

namespace Tests\Feature;

use App\Services\Codex\AgentOutput;
use Tests\TestCase;

class AgentOutputTest extends TestCase
{
    public function test_removes_control_codes_and_redacts_configured_credentials_and_bearer_tokens(): void
    {
        config(['database.connections.pgsql.password' => 'private-database-password']);
        $text = (new AgentOutput)->text("hello\x00 private-database-password Bearer private.token.value");
        $this->assertSame('hello [redacted] [redacted]', $text);
    }
}
