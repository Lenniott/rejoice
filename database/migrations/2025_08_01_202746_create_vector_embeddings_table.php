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
        Schema::create('vector_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('note_id');
            $table->uuid('audio_id')->nullable()->comment('Optional: specific audio source');
            $table->json('chunk_ids')->nullable()->comment('Array of chunk IDs included in this embedding');
            $table->uuid('qdrant_point_id')->unique()->comment('Reference to Qdrant vector point');
            $table->text('source_text')->comment('Text content that was vectorized');
            $table->string('embedding_model', 100)->default('models/embedding-001')->comment('AI model used for embeddings');
            $table->string('text_hash', 64)->nullable()->comment('SHA-256 hash for change detection (re-embed if >20% diff)');
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('note_id')->references('id')->on('notes')->onDelete('cascade');
            $table->foreign('audio_id')->references('id')->on('audio_files')->onDelete('set null');
            
            // Indexes
            $table->index('note_id', 'idx_vector_embeddings_note_id');
            $table->index('audio_id', 'idx_vector_embeddings_audio_id');
            $table->unique('qdrant_point_id', 'idx_vector_embeddings_qdrant_id');
            $table->index('text_hash', 'idx_vector_embeddings_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_embeddings');
    }
};
