<?php

namespace App\Jobs;

use App\Enums\DetectionStatus;
use App\Models\Detection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;
use RuntimeException;

class RunDetection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    public function __construct(
        public int $detectionId
    ) {
    }

    public function handle(): void
    {
        $detection = Detection::with('image')->findOrFail($this->detectionId);

        // Idempotency check
        if ($detection->status === DetectionStatus::Completed) {
            return;
        }

        $detection->update([
            'status' => DetectionStatus::Processing,
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $image = $detection->image;

            /*
             * Gemini detection logic
             * from DetectObjects command
             */
            $result = app(\App\Services\GeminiDetectionService::class)
                ->detect($image);

            if (empty($result['objects'])) {
                throw new RuntimeException('Detection returned no objects');
            }

            DB::transaction(function () use ($detection, $result) {

                /*
                 * Clear partially-created objects.
                 * Makes retry idempotent.
                 */
                $detection->objects()->delete();

                foreach ($result['objects'] as $object) {
                    $detection->objects()->create([
                        'label' => $object['label'],
                        'confidence' => $object['confidence'] ?? null,

                        'bbox_x' => $object['x'] ?? null,
                        'bbox_y' => $object['y'] ?? null,
                        'bbox_width' => $object['width'] ?? null,
                        'bbox_height' => $object['height'] ?? null,
                    ]);
                }

                $usage = $result['usage'] ?? [];

                $detection->update([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                    'output_tokens' => $usage['output_tokens'] ?? 0,
                    'thought_tokens' => $usage['thought_tokens'] ?? 0,
                    'total_tokens' => $usage['total_tokens'] ?? 0,

                    'status' => DetectionStatus::Completed,
                    'completed_at' => now(),
                    'error_message' => null,
                ]);
            });

        } catch (Throwable $e) {

            /*
             * Re-throw the exception.
             *
             * Laravel Queue will automatically retry
             * according to $tries and $backoff.
             */
            throw $e;
        }
    }

    /**
     * Called by Laravel after all retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $detection = Detection::find($this->detectionId);

        if (!$detection) {
            return;
        }

        $detection->update([
            'status' => DetectionStatus::Failed,
            'error_message' => $exception?->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
