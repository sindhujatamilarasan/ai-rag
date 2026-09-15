<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
        {
            // HNSW with cosine distance, matching the normalized vectors we store.
            // Built now because rows exist; indexing an empty table does nothing.
            DB::statement(
                'CREATE INDEX detected_objects_embedding_idx
                ON detected_objects USING hnsw (embedding vector_cosine_ops)'
            );
        }

        public function down(): void
        {
            DB::statement('DROP INDEX IF EXISTS detected_objects_embedding_idx');
        }
};
