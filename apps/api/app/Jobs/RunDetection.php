<?php

namespace App\Jobs;

use App\Enums\DetectionStatus;
use App\Models\Detection;
use App\Services\DetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class RunDetection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 180;

    public function __construct(public int $detectionId)
    {
    }

    public function handle(): void
    {
        $detection = Detection::with('image')->findOrFail($this->detectionId);

        // A previous attempt may already have finished this run. Comparing to the
        // enum case, not the string — status is cast, so === 'completed' is always
        // false and the guard would never fire.
        if ($detection->status === DetectionStatus::Completed) {
            return;
        }

        $detection->update([
            'status'        => DetectionStatus::Processing,
            'started_at'    => now(),
            'error_message' => null,
        ]);

        try {
            $result = app(DetectionService::class)->detect($detection->image);

            // A job that reports success while doing nothing is worse than one
            // that crashes — a silent failure fills the database with empty rows.
            if (empty($result['objects'])) {
                throw new RuntimeException('Detection returned no objects');
            }

            // Embed the labels so images become searchable by meaning rather than
            // exact text. Kept outside the transaction for the same reason the
            // detect call is: never hold a database transaction open across a
            // network call — the connection pool drains while you wait.
            $vectors = Http::timeout(60)
                ->post(
                    config('services.ai.url') . '/embed',
                    ['texts' => array_column($result['objects'], 'label')],
                )
                ->throw()
                ->json('embeddings');

            DB::transaction(function () use ($detection, $result, $vectors) {
                // Clear anything a crashed earlier attempt left behind, so a retry
                // cannot produce duplicate objects.
                $detection->objects()->delete();

                foreach ($result['objects'] as $i => $object) {
                    $created = $detection->objects()->create([
                        'label'       => $object['label'],
                        'confidence'  => $object['confidence'],
                        'bbox_x'      => $object['x'] ?? null,
                        'bbox_y'      => $object['y'] ?? null,
                        'bbox_width'  => $object['width'] ?? null,
                        'bbox_height' => $object['height'] ?? null,
                    ]);

                    // Eloquent has no vector type; pgvector accepts the literal '[1,2,3]'.
                    DB::statement(
                        'UPDATE detected_objects SET embedding = ? WHERE id = ?',
                        ['[' . implode(',', $vectors[$i]) . ']', $created->id],
                    );
                }

                $usage = $result['usage'] ?? [];

                $detection->update([
                    'prompt_tokens'  => $usage['prompt_tokens']  ?? 0,
                    'output_tokens'  => $usage['output_tokens']  ?? 0,
                    'thought_tokens' => $usage['thought_tokens'] ?? 0,
                    'total_tokens'   => $usage['total_tokens']   ?? 0,
                    'status'         => DetectionStatus::Completed,
                    'completed_at'   => now(),
                    'error_message'  => null,
                ]);
            });
        } catch (Throwable $e) {
            // A 4xx means the request itself is wrong — a bad schema, a bad key,
            // invalid input. Retrying sends the identical broken request again and
            // burns three attempts plus 100 seconds on a guaranteed failure.
            // 429 is the exception: it means slow down, so it is worth retrying.
            if ($e instanceof RequestException
                && $e->response->status() >= 400
                && $e->response->status() < 500
                && $e->response->status() !== 429) {

                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    /**
     * Called once every attempt is exhausted — and, unlike the catch block above,
     * also when the worker is killed or the job times out. Without this a detection
     * can sit in `processing` forever with nothing to explain why.
     */
    public function failed(Throwable $e): void
    {
        Detection::whereKey($this->detectionId)->update([
            'status'        => DetectionStatus::Failed->value,
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
            'completed_at'  => now(),
        ]);
    }
}
