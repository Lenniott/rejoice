<?php

/**
 * VectorEmbedding Model - Metadata for vector embeddings stored in Qdrant for semantic search
 * 
 * Requirements:
 * - Belongs to a Note (note_id foreign key)
 * - Optionally belongs to an AudioFile (audio_id foreign key) 
 * - Stores reference to Qdrant vector point via qdrant_point_id
 * - Tracks source text that was vectorized and which AI model was used
 * - chunk_ids array links to specific Chunk records included in embedding
 * - text_hash enables change detection for re-embedding when >20% content changes
 * - Uses UUID as primary key
 * 
 * Flow:
 * - Text content prepared -> VectorEmbedding record created with source_text
 * - Text sent to AI model -> Embedding vector created and stored in Qdrant
 * - qdrant_point_id stored to link database record to vector in Qdrant
 * - text_hash computed for future change detection
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VectorEmbedding extends Model
{
    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'note_id',
        'audio_id',
        'chunk_ids',
        'qdrant_point_id',
        'source_text',
        'embedding_model',
        'text_hash',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'chunk_ids' => 'array',
    ];

    /**
     * Boot the model and generate UUID for new records.
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            
            if (empty($model->qdrant_point_id)) {
                $model->qdrant_point_id = (string) Str::uuid();
            }
            
            // Generate text hash if source_text is provided
            if (!empty($model->source_text) && empty($model->text_hash)) {
                $model->text_hash = hash('sha256', $model->source_text);
            }
        });
    }

    /**
     * Get the note that owns the vector embedding.
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    /**
     * Get the audio file that this embedding is linked to (optional).
     */
    public function audioFile(): BelongsTo
    {
        return $this->belongsTo(AudioFile::class, 'audio_id');
    }

    /**
     * Check if the source text has changed significantly (>20% difference).
     */
    public function hasTextChangedSignificantly(string $newText): bool
    {
        if (empty($this->source_text)) {
            return true;
        }
        
        $oldWords = str_word_count($this->source_text);
        $newWords = str_word_count($newText);
        
        if ($oldWords === 0) {
            return $newWords > 0;
        }
        
        $changePercentage = abs($newWords - $oldWords) / $oldWords;
        
        return $changePercentage > 0.2; // 20% threshold
    }
}
