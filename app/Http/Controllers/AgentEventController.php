<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentEvent;
use App\Services\Codex\AgentPresenter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentEventController extends Controller
{
    public function __invoke(Request $request, Agent $agent, AgentPresenter $presenter): StreamedResponse
    {
        abort_unless($agent->user_id === $request->user()->id, 404);
        $request->validate(['after' => ['sometimes', 'integer', 'min:0']]);
        $header = $request->header('Last-Event-ID', '0');
        abort_unless(ctype_digit($header), 422, 'Invalid event cursor.');
        $cursor = max((int) $header, (int) $request->query('after', 0));

        return response()->stream(function () use ($agent, $presenter, $cursor): void {
            $deadline = microtime(true) + (float) config('agents.stream_seconds');
            echo "retry: 1000\n\n";

            do {
                $events = AgentEvent::query()->where('agent_id', $agent->id)->where('id', '>', $cursor)->orderBy('id')->limit(100)->get();

                foreach ($events as $event) {
                    echo 'id: '.$event->id."\nevent: workshop\ndata: ".json_encode(['id' => $event->id, 'type' => $event->type, 'data' => $event->data], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                    $cursor = $event->id;
                }

                echo 'event: health'."\ndata: ".json_encode($presenter->status($agent->fresh()), JSON_THROW_ON_ERROR)."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();

                if (connection_aborted() || microtime(true) >= $deadline) {
                    break;
                }

                if ($events->count() < 100) {
                    usleep(250000);
                }
            } while (true);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-store, no-cache', 'X-Accel-Buffering' => 'no']);
    }
}
