<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('app:ping-ai-service')]
#[Description('Command description')]
class PingAiService extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $response = Http::timeout(5)->get(config('services.ai.url') . '/health');

        $this->info('Status: ' . $response->status());
        dump($response->json());
    }
}
