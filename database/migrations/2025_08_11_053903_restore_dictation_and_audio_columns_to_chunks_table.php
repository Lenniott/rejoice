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
        Schema::table('chunks', function (Blueprint $table) {
            // Add back dictation_text column for voice notes
            $table->text('dictation_text')->nullable()->comment('Raw browser dictation API output');
            
            // Add back audio_id column for linking chunks to recordings
            $table->uuid('audio_id')->nullable()->comment('Optional: links chunk to specific recording');
            
            // Add back the foreign key constraint and index for audio_id
            $table->foreign('audio_id')->references('id')->on('audio_files')->onDelete('set null');
            $table->index('audio_id', 'idx_chunks_audio_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            // Drop foreign key constraint and index first
            $table->dropForeign(['audio_id']);
            $table->dropIndex('idx_chunks_audio_id');
            
            // Drop the columns
            $table->dropColumn(['dictation_text', 'audio_id']);
        });
    }
};
