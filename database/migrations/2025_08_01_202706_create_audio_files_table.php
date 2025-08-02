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
        Schema::create('audio_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('note_id');
            $table->text('path')->comment('Filesystem path to .webm file (storage/app/audio/{note_id}/{uuid}.webm)');
            $table->integer('duration_seconds')->nullable()->comment('Audio duration for UI display');
            $table->bigInteger('file_size_bytes')->nullable()->comment('File size for storage management');
            $table->string('mime_type', 50)->default('audio/webm')->comment('Audio format');
            $table->timestamp('created_at')->useCurrent();

            // Foreign key constraints
            $table->foreign('note_id')->references('id')->on('notes')->onDelete('cascade');
            
            // Indexes
            $table->index('note_id', 'idx_audio_files_note_id');
            $table->index('created_at', 'idx_audio_files_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_files');
    }
};
