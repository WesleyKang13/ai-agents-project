<?php

namespace Tests\Unit\Services\Codex;

use App\Services\Codex\JsonLineDecoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JsonLineDecoderTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_reassembles_partial_utf8_messages_and_multiple_lines(): void
    {
        $decoder = new JsonLineDecoder;
        $wire = "{\"id\":1,\"result\":\"café\"}\r\n\n{\"method\":\"done\"}\n";
        $messages = [];

        foreach (str_split($wire) as $byte) {
            $messages = [...$messages, ...$decoder->feed($byte)];
        }

        $decoder->finish();
        $this->assertSame([['id' => 1, 'result' => 'café'], ['method' => 'done']], $messages);
    }

    public function test_rejects_malformed_json_without_disclosing_the_payload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON from App Server; payload suppressed.');
        (new JsonLineDecoder)->feed("private-token\n");
    }

    public function test_rejects_a_batch_instead_of_a_message_object(): void
    {
        $this->expectExceptionMessage('Expected an App Server message object.');
        (new JsonLineDecoder)->feed("[]\n");
    }

    public function test_rejects_an_unterminated_message_at_eof(): void
    {
        $decoder = new JsonLineDecoder;
        $decoder->feed('{"id":');
        $this->expectExceptionMessage('App Server exited with a partial JSON message.');
        $decoder->finish();
    }

    public function test_bounds_unterminated_input(): void
    {
        $this->expectExceptionMessage('App Server message exceeds the 8 MiB limit.');
        (new JsonLineDecoder)->feed(str_repeat('x', 8 * 1024 * 1024 + 1));
    }
}
