<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The extension is part of the schema, so it belongs in a migration —
        // not a psql command somebody has to remember to run.
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::statement('ALTER TABLE detected_objects DROP COLUMN IF EXISTS embedding');
        DB::statement('ALTER TABLE detected_objects ADD COLUMN embedding vector(768)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE detected_objects DROP COLUMN IF EXISTS embedding');
    }
};
