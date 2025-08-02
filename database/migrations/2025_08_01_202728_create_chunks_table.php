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
        Schema::create('chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('note_id');
            $table->uuid('audio_id')->nullable()->comment('Optional: links chunk to specific recording');
            $table->text('dictation_text')->nullable()->comment('Raw browser dictation API output');
            $table->text('ai_text')->nullable()->comment('AI-refined version using Gemini 2.5 Flash');
            $table->text('edited_text')->nullable()->comment('User-edited final version');
            $table->string('active_version', 10)->default('dictation')->comment('dictation | ai | edited');
            $table->integer('chunk_order')->default(0)->comment('Order of chunk within note');
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('note_id')->references('id')->on('notes')->onDelete('cascade');
            $table->foreign('audio_id')->references('id')->on('audio_files')->onDelete('set null');
            
            // Indexes
            $table->index('note_id', 'idx_chunks_note_id');
            $table->index('audio_id', 'idx_chunks_audio_id');
            $table->index(['note_id', 'chunk_order'], 'idx_chunks_note_order');
            $table->index('active_version', 'idx_chunks_active_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
