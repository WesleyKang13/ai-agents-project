<?php

/** Scripted stdio peer: no Codex binary, authentication or network/model access. */
$scenario = $argv[1];

if ($scenario === 'exit') {
    fwrite(STDERR, 'secret-test-token');
    exit(23);
}

if ($scenario === 'partial-exit') {
    echo '{"id":';
    exit(0);
}

function send(array $message): void
{
    echo json_encode($message, JSON_THROW_ON_ERROR)."\n";
    flush();
}

$decision = null;

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, 128, JSON_THROW_ON_ERROR);

    if ($scenario === 'hang') {
        continue;
    }

    if (! isset($message['method'])) {
        $decision = $message['result'] ?? $message['error'];

        continue;
    }

    if ($message['method'] === 'probe') {
        fwrite(STDERR, 'secret-test-token');
        send(['id' => 1, 'method' => 'item/commandExecution/requestApproval', 'params' => ['threadId' => 'thread-1', 'turnId' => 'turn-1', 'itemId' => 'item-1', 'command' => 'echo test']]);
        send(['method' => 'item/agentMessage/delta', 'params' => ['delta' => 'hello']]);
        send(['method' => 'turn/completed', 'params' => ['threadId' => 'thread-1', 'turn' => ['id' => 'turn-1', 'status' => 'completed']]]);
        $response = json_encode(['id' => $message['id'], 'result' => ['ok' => true]], JSON_THROW_ON_ERROR)."\n";
        echo substr($response, 0, 7);
        flush();
        echo substr($response, 7);
        flush();
    } elseif ($message['method'] === 'verify') {
        send(['id' => $message['id'], 'result' => ['decision' => $decision, 'databaseSecret' => getenv('DB_PASSWORD'), 'apiKey' => getenv('OPENAI_API_KEY')]]);
    } elseif ($message['method'] === 'error') {
        send(['id' => $message['id'], 'error' => ['code' => -32602, 'message' => 'secret-test-token']]);
    } elseif ($message['method'] === 'unmatched') {
        send(['id' => 9999, 'result' => []]);
    } elseif ($message['method'] === 'malformed') {
        echo "not json secret-test-token\n";
        flush();
    } elseif ($message['method'] === 'descendant') {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {});
        $child = pcntl_fork();

        if ($child === 0) {
            posix_setsid();
            pcntl_signal(SIGTERM, SIG_DFL);
            sleep(30);
            exit(0);
        }

        send(['id' => $message['id'], 'result' => ['child' => $child]]);

        while (fgets(STDIN) !== false) {
        }

        while (pcntl_waitpid($child, $status) === -1 && pcntl_get_last_error() === PCNTL_EINTR) {
        }

        exit(0);
    }
}
