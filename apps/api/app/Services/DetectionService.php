<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DetectionService
{
    public function detect(Image $image): array
    {
        $path = Storage::disk('public')->path($image->path);

        if (! file_exists($path)) {
            throw new RuntimeException("Image file missing: {$image->path}");
        }

        $response = Http::timeout(150)
            ->attach('image', file_get_contents($path), basename($path))
            ->post(config('services.ai.url') . '/detect')
            // throw() turns a 4xx/5xx into a RequestException, which is what the
            // job's retry logic inspects to decide retryable vs permanent.
            ->throw();

        $data = $response->json();

        if (empty($data['objects'])) {
            throw new RuntimeException('AI service returned no objects');
        }

        return $data;
    }
}
