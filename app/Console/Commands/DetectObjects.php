<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('app:detect-objects')]
#[Description('Command description')]
class DetectObjects extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
         $path = storage_path('app/test1.jpg');

        if (! file_exists($path)) {
            $this->error("Image illa: {$path}");
            return 1;
        }

        $base64 = base64_encode(file_get_contents($path));

        $response = Http::timeout(60)->withHeaders([
            'x-goog-api-key' => config('services.gemini.key'),
        ])->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'
                . config('services.gemini.model') . ':generateContent',
            [
                'contents' => [[
                    'parts' => [
                            ['text' => 'Detect every distinct visible item in this image, including background items, surfaces, furniture and small objects. Do not only return the main subject — list everything you can see. Give each item a short label and your confidence from 0 to 1.'],
                            ['inline_data' => [
                            'mime_type' => mime_content_type($path),
                            'data' => $base64,
                        ]],
                    ],
                ]],
              'generationConfig' => [
                    'thinkingConfig' => ['thinkingBudget' => 0],
                    'responseMimeType' => 'application/json',
                    'responseSchema' => [
                        'type' => 'ARRAY',
                        'items' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'label' => ['type' => 'STRING'],
                                'confidence' => ['type' => 'NUMBER'],
                            ],
                            'required' => ['label', 'confidence'],
                        ],
                    ],
                ],
             ]
        );

        $this->info('Status: ' . $response->status());
        $this->line($response->json()['candidates'][0]['content']['parts'][0]['text'] ?? 'text illa');
        dump($response->json()['usageMetadata']);
    }
}
