<?php

/**
 * AudioFile Model - References to audio files stored on filesystem
 * 
 * Requirements:
 * - Belongs to a Note (note_id foreign key)
 * - Stores metadata about .webm audio files
 * - File path follows pattern: storage/app/audio/{note_id}/{uuid}.webm
 * - Tracks duration, file size, mime type for UI and storage management
 * - Uses UUID as primary key
 * 
 * Flow:
 * - User records audio -> AudioFile record created with note_id and file metadata
 * - Audio file saved to filesystem at specified path
 * - AudioFile can be linked to Chunk records for transcription
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AudioFile extends Model
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
     * Disable updated_at timestamp (only created_at needed)
     */
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'note_id',
        'path',
        'duration_seconds',
        'file_size_bytes',
        'mime_type',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'duration_seconds' => 'integer',
        'file_size_bytes' => 'integer',
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
        });
    }

    /**
     * Get the note that owns the audio file.
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    /**
     * Get the chunks associated with this audio file.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'audio_id');
    }

    /**
     * Get the vector embeddings associated with this audio file.
     */
    public function vectorEmbeddings(): HasMany
    {
        return $this->hasMany(VectorEmbedding::class, 'audio_id');
    }
}
