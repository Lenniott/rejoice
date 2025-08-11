<?php

/**
 * AudioController - Handles audio upload, storage, and initial chunk creation
 * 
 * Requirements:
 * - Accept audio file uploads (webm format)
 * - Accept dictation text with audio
 * - Store audio file using AudioService
 * - Create initial chunk with dictation text
 * - Return audio_id and chunk_id for frontend processing
 * - Handle file validation and storage errors gracefully
 * 
 * Flow:
 * - POST /api/notes/{noteId}/audio -> Uploads audio + dictation, creates chunk
 * - Returns audio_id and chunk_id for subsequent operations
 * - Triggers background vectorization job for the new content
 */

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Chunk;
use App\Services\AudioService;
use App\Jobs\VectorizeContentJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AudioController extends Controller
{
    protected AudioService $audioService;

    public function __construct(AudioService $audioService)
    {
        $this->audioService = $audioService;
    }

    /**
     * Upload audio file and create initial chunk with dictation text.
     */
    public function store(Request $request, string $noteId): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:webm|max:102400', // 100MB max
            'dictation_text' => 'required|string|max:10000',
        ]);

        $note = Note::where('id', $noteId)
                    ->where('user_id', $request->user()->id)
                    ->firstOrFail();
        $audioFile = $request->file('audio');
        $dictationText = $request->input('dictation_text');

        try {
            // Store audio file using AudioService
            $audioFile = $this->audioService->storeAudioFile($note->id, $audioFile);
            
            // Create initial chunk with dictation text
            $chunk = Chunk::create([
                'note_id' => $note->id,
                'audio_id' => $audioFile->id,
                'dictation_text' => $dictationText,
                'active_version' => 'dictation',
                'chunk_order' => 1,
            ]);

            // Queue vectorization job for the new content
            VectorizeContentJob::dispatch($note->id, $audioFile->id, [$chunk->id]);

            return response()->json([
                'audio_id' => $audioFile->id,
                'chunk_id' => $chunk->id,
                'dictation_text' => $dictationText,
                'message' => 'Audio uploaded and chunk created successfully'
            ], 201);

        } catch (\Exception $e) {
            // Clean up any partially created data
            if (isset($audioFile)) {
                $this->audioService->deleteAudioFile($audioFile->id);
            }
            if (isset($chunk)) {
                $chunk->delete();
            }

            return response()->json([
                'error' => 'Failed to process audio upload',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
