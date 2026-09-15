<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detected_objects', function (Blueprint $table) {
            // Postgres does not index foreign keys automatically the way MySQL does.
            $table->index('detection_id');
            $table->index('label');
        });
    }

    public function down(): void
    {
        Schema::table('detected_objects', function (Blueprint $table) {
            $table->dropIndex(['detection_id']);
            $table->dropIndex(['label']);
        });
    }
};
