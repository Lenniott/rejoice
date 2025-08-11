<?php

/**
 * AudioFile Model - References to audio files for transcription processing
 * 
 * Requirements:
 * - Belongs to a Note (note_id foreign key)
 * - Stores metadata about audio files
 * - File path follows pattern: storage/app/audio/{note_id}/{uuid}.{ext}
 * - Tracks duration, file size, mime type for processing
 * - Status tracking: uploaded -> processing -> transcribed -> deleted
 * - Verification fields ensure transcript persistence before audio deletion
 * - Uses UUID as primary key
 * 
 * Flow:
 * - User uploads audio -> AudioFile record created with status 'uploaded'
 * - Transcription job processes -> status 'processing' -> 'transcribed'
 * - Verification confirms chunks persisted -> status 'deleted', audio file removed
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
        'status',
        'transcribed_at',
        'transcript_verified_at',
        'transcript_chunk_count',
        'embedding_window_count',
        'transcript_checksum',
        'delete_error',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'duration_seconds' => 'integer',
        'file_size_bytes' => 'integer',
        'transcribed_at' => 'datetime',
        'transcript_verified_at' => 'datetime',
        'transcript_chunk_count' => 'integer',
        'embedding_window_count' => 'integer',
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


}
