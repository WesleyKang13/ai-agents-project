<?php

namespace App\Services\Codex;

class AgentOutput
{
    public function text(?string $text, int $limit = 131072): string
    {
        $text = mb_substr($text ?? '', 0, $limit);
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text) ?? '';

        foreach ([config('app.key'), config('database.connections.pgsql.password'), config('database.connections.mysql.password')] as $secret) {
            if (is_string($secret) && strlen($secret) >= 8) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return preg_replace('/(?:Bearer\s+[A-Za-z0-9._~+\/-]+|\bsk-[A-Za-z0-9_-]{12,})/i', '[redacted]', $text) ?? '';
    }
}
