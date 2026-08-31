<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GeminiDetectionService
{
    private const PROMPT = 'Detect every distinct visible item in this image, '
    . 'including background items, surfaces, furniture and small objects. '
    . 'Do not only return the main subject — list everything you can see. '
    . 'Label each item with a lowercase singular noun phrase and no article '
    . '("pen", not "a pen"; "desk", not "the desks"). '
    . 'Give your confidence from 0 to 1, and a bounding box where x and y are '
    . 'the top-left corner, all four values normalized between 0 and 1 '
    . 'relative to the image width and height.';

    public function detect(Image $image): array
    {
        $path = Storage::disk('public')->path($image->path);

        if (! file_exists($path)) {
            throw new RuntimeException("Image file missing: {$image->path}");
        }

        $response = Http::timeout(60)
            ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'
                    . config('services.gemini.model') . ':generateContent',
                [
                    'contents' => [[
                        'parts' => [
                            ['text' => self::PROMPT],
                            ['inline_data' => [
                                'mime_type' => mime_content_type($path),
                                'data'      => base64_encode(file_get_contents($path)),
                            ]],
                        ],
                    ]],
                    'generationConfig' => [
                        'thinkingConfig'   => ['thinkingBudget' => 0],
                        'responseMimeType' => 'application/json',
                        'responseSchema'   => [
                            'type'  => 'ARRAY',
                            'items' => [
                                'type'       => 'OBJECT',
                                'properties' => [
                                    'label'      => ['type' => 'STRING'],
                                    'confidence' => ['type' => 'NUMBER'],
                                    'x'          => ['type' => 'NUMBER'],
                                    'y'          => ['type' => 'NUMBER'],
                                    'width'      => ['type' => 'NUMBER'],
                                    'height'     => ['type' => 'NUMBER'],
                                ],
                                'required' => ['label', 'confidence', 'x', 'y', 'width', 'height'],
                            ],
                        ],
                    ],
                ]
            );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Gemini API failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        $body = $response->json();

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null) {
            throw new RuntimeException('Gemini returned no text part');
        }

        // Gemini gives the JSON back as a *string* inside the text part,
        // so it needs decoding a second time.
        $objects = json_decode($text, true);

        if (! is_array($objects)) {
            throw new RuntimeException('Could not decode detection JSON: ' . $text);
        }

        $usage = $body['usageMetadata'] ?? [];

        return [
            'objects' => $objects,
            'usage'   => [
                'prompt_tokens'  => $usage['promptTokenCount'] ?? 0,
                'output_tokens'  => $usage['candidatesTokenCount'] ?? 0,
                'thought_tokens' => $usage['thoughtsTokenCount'] ?? 0,
                'total_tokens'   => $usage['totalTokenCount'] ?? 0,
            ],
        ];
    }
}
