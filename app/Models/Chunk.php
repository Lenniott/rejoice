<?php

/**
 * Chunk Model - Editable text blocks linked to audio or manually created
 * 
 * Requirements:
 * - Belongs to a Note (note_id foreign key)
 * - Optionally belongs to an AudioFile (audio_id foreign key)
 * - Stores three versions of text: dictation (raw), ai (refined), edited (user)
 * - active_version field determines which text version to display
 * - chunk_order maintains sequence within a note
 * - Uses UUID as primary key
 * 
 * Flow:
 * - Audio transcribed -> Chunk created with dictation_text and active_version='dictation'
 * - AI refines text -> ai_text populated, active_version can be switched to 'ai'
 * - User edits -> edited_text populated, active_version switched to 'edited'
 * - User can toggle between versions via active_version field
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Chunk extends Model
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
        'dictation_text',
        'ai_text',
        'edited_text',
        'active_version',
        'chunk_order',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'chunk_order' => 'integer',
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
     * Get the note that owns the chunk.
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    /**
     * Get the audio file that this chunk is linked to (optional).
     */
    public function audioFile(): BelongsTo
    {
        return $this->belongsTo(AudioFile::class, 'audio_id');
    }

    /**
     * Get the active text based on the active_version.
     */
    public function getActiveTextAttribute(): ?string
    {
        return match($this->active_version) {
            'dictation' => $this->dictation_text,
            'ai' => $this->ai_text,
            'edited' => $this->edited_text,
            default => $this->dictation_text,
        };
    }

    /**
     * Scope to get chunks in order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('chunk_order');
    }
}
