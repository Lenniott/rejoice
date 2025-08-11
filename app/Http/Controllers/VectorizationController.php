<?php

/**
 * VectorizationController - Handles manual re-embedding triggers for notes and chunks
 * 
 * Requirements:
 * - Allow manual triggering of vectorization for specific notes
 * - Support both note-level and chunk-level re-embedding
 * - Queue background jobs for vectorization processing
 * - Return status messages for queued operations
 * - Handle validation of note existence and vectorization types
 * 
 * Flow:
 * - POST /api/vectorize/run -> Triggers vectorization for specific note or all content
 * - POST /api/notes/{id}/vectorize -> Triggers vectorization for specific note
 * - Both endpoints queue background jobs and return immediate confirmation
 */

namespace App\Http\Controllers;

use App\Models\Note;
use App\Jobs\VectorizeContentJob;
use App\Services\VectorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class VectorizationController extends Controller
{
    protected VectorService $vectorService;

    public function __construct(VectorService $vectorService)
    {
        $this->vectorService = $vectorService;
    }

    /**
     * Trigger vectorization for specific note or all content.
     */
    public function runVectorization(Request $request): JsonResponse
    {
        $request->validate([
            'note_id' => 'nullable|string|uuid',
            'type' => 'nullable|in:note-level,chunk-level,both',
        ]);

        $noteId = $request->input('note_id');
        $type = $request->input('type', 'both');

        try {
            if ($noteId) {
                // Vectorize specific note
                $note = Note::where('id', $noteId)
                            ->where('user_id', $request->user()->id)
                            ->firstOrFail();
                
                if ($type === 'note-level' || $type === 'both') {
                    $this->vectorService->vectorizeNote($note->id);
                }
                
                if ($type === 'chunk-level' || $type === 'both') {
                    // Get all chunks for the note and queue vectorization
                    $chunks = $note->chunks()->pluck('id')->toArray();
                    if (!empty($chunks)) {
                        VectorizeContentJob::dispatch($note->id, null, $chunks);
                    }
                }

                return response()->json([
                    'message' => "Vectorization started for note: {$note->title}",
                    'note_id' => $noteId,
                    'type' => $type,
                ]);
            } else {
                // Vectorize all notes (use with caution)
                return response()->json([
                    'message' => 'Bulk vectorization not implemented for safety',
                    'note' => 'Use specific note_id parameter for targeted vectorization',
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Vectorization failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger vectorization for a specific note.
     */
    public function vectorizeNote(Request $request, string $noteId): JsonResponse
    {
        try {
            $note = Note::where('id', $noteId)
                        ->where('user_id', $request->user()->id)
                        ->firstOrFail();
            
            // Trigger note-level vectorization
            $this->vectorService->vectorizeNote($note->id);
            
            // Trigger chunk-level vectorization if chunks exist
            $chunks = $note->chunks()->pluck('id')->toArray();
            if (!empty($chunks)) {
                VectorizeContentJob::dispatch($note->id, null, $chunks);
            }

            return response()->json([
                'message' => "Note vectorization queued: {$note->title}",
                'note_id' => $noteId,
                'vectorization_type' => 'note-level + chunk-level',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Note vectorization failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
