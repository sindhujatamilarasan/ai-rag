<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('app:backfill-embeddings')]
#[Description('Command description')]
class BackfillEmbeddings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $missing = DB::table('detected_objects')
            ->whereNull('embedding')
            ->select('id', 'label')
            ->get();

        if ($missing->isEmpty()) {
            $this->info('Nothing to backfill.');
            return 0;
        }

        $this->info("Backfilling {$missing->count()} objects...");

        // Chunked so one huge request cannot time out, and so a failure halfway
        // through leaves the earlier chunks already saved rather than losing all of it.
        foreach ($missing->chunk(50) as $chunk) {
            $vectors = Http::timeout(120)
                ->post(config('services.ai.url') . '/embed',
                    ['texts' => $chunk->pluck('label')->all()])
                ->throw()
                ->json('embeddings');

            foreach ($chunk->values() as $i => $row) {
                DB::statement(
                    'UPDATE detected_objects SET embedding = ? WHERE id = ?',
                    ['[' . implode(',', $vectors[$i]) . ']', $row->id],
                );
            }

            $this->line("  {$chunk->count()} done");
        }

        $this->info('Backfill complete.');
        return 0;
    }
}
