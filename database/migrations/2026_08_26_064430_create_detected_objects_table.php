<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('detected_objects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('detection_id')
                ->constrained('detections')
                ->cascadeOnDelete();

            $table->string('label');
            $table->decimal('confidence', 8, 6)->nullable();

            $table->decimal('bbox_x', 12, 6)->nullable();
            $table->decimal('bbox_y', 12, 6)->nullable();
            $table->decimal('bbox_width', 12, 6)->nullable();
            $table->decimal('bbox_height', 12, 6)->nullable();

            // Milestone 10
            $table->json('embedding')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detected_objects');
    }
};
