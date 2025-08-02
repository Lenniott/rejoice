<?php

/**
 * Note Model - Container for voice notes, can have multiple audio recordings
 * 
 * Requirements:
 * - Belongs to a User (user_id foreign key)
 * - Has many AudioFile recordings 
 * - Has many Chunk text blocks
 * - Uses UUID as primary key
 * - Title defaults to timestamp if not provided
 * 
 * Flow:
 * - User creates note -> Note record created with user_id
 * - User records audio -> AudioFile records linked to note_id 
 * - Audio transcribed -> Chunk records created linked to note_id and optionally audio_id
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Note extends Model
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
        'user_id',
        'title',
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
            
            // Set default title to timestamp if not provided
            if (empty($model->title)) {
                $model->title = now()->format('Y-m-d H:i:s');
            }
        });
    }

    /**
     * Get the user that owns the note.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the audio files for the note.
     */
    public function audioFiles(): HasMany
    {
        return $this->hasMany(AudioFile::class);
    }

    /**
     * Get the chunks for the note.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    /**
     * Get the vector embeddings for the note.
     */
    public function vectorEmbeddings(): HasMany
    {
        return $this->hasMany(VectorEmbedding::class);
    }
}
