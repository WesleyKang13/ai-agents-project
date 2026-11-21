<?php

namespace App\Services\Codex;

use JsonException;
use RuntimeException;

class JsonLineDecoder
{
    private string $buffer = '';

    /** @return list<array<string, mixed>> */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $messages = [];

        while (($position = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + 1);
            $this->checkSize($line);

            if (trim($line) === '') {
                continue;
            }

            try {
                $message = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Invalid JSON from App Server; payload suppressed.');
            }

            if (! is_array($message) || array_is_list($message)) {
                throw new RuntimeException('Expected an App Server message object.');
            }

            $messages[] = $message;
        }

        $this->checkSize($this->buffer);

        return $messages;
    }

    public function finish(): void
    {
        if (trim($this->buffer) !== '') {
            throw new RuntimeException('App Server exited with a partial JSON message.');
        }
    }

    private function checkSize(string $line): void
    {
        if (strlen($line) > 8 * 1024 * 1024) {
            throw new RuntimeException('App Server message exceeds the 8 MiB limit.');
        }
    }
}
