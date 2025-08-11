<?php

/**
 * ChunksController - Handles chunk editing and AI processing operations
 * 
 * Requirements:
 * - Allow editing of chunk text with version tracking
 * - Trigger AI processing of chunks for text enhancement
 * - Update active_version field based on user actions
 * - Handle AI processing failures gracefully
 * - Return updated chunk data for frontend consumption
 * 
 * Flow:
 * - PATCH /api/chunks/{id} -> Updates chunk text, sets active_version to 'edited'
 * - POST /api/chunks/{id}/ai-process -> Triggers AI enhancement, sets active_version to 'ai'
 * - Both operations trigger re-vectorization of updated content
 */

namespace App\Http\Controllers;

use App\Models\Chunk;
use App\Services\AIService;
use App\Jobs\ProcessChunkWithAI;
use App\Jobs\VectorizeContentJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ChunksController extends Controller
{
    protected AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Update the specified chunk in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'edited_text' => 'required|string|max:10000',
            'active_version' => 'required|in:dictation,edited,ai',
        ]);

        $chunk = Chunk::where('id', $id)
                      ->whereHas('note', function ($query) use ($request) {
                          $query->where('user_id', $request->user()->id);
                      })
                      ->firstOrFail();
        
        $chunk->update([
            'edited_text' => $request->input('edited_text'),
            'active_version' => $request->input('active_version'),
        ]);

        // Queue re-vectorization for the updated content
        VectorizeContentJob::dispatch(
            $chunk->note_id, 
            $chunk->audio_id, 
            [$chunk->id]
        );

        return response()->json($chunk);
    }

    /**
     * Process chunk with AI for text enhancement.
     */
    public function aiProcess(Request $request, string $id): JsonResponse
    {
        $chunk = Chunk::where('id', $id)
                      ->whereHas('note', function ($query) use ($request) {
                          $query->where('user_id', $request->user()->id);
                      })
                      ->firstOrFail();

        try {
            // Process chunk with AI (this updates the chunk directly)
            $success = $this->aiService->processChunk($chunk->id);
            
            if (!$success) {
                return response()->json([
                    'error' => 'AI processing failed',
                    'message' => 'Unable to process chunk with AI'
                ], 500);
            }

            // Refresh the chunk to get the updated data
            $chunk->refresh();

            // Queue re-vectorization for the AI-enhanced content
            VectorizeContentJob::dispatch(
                $chunk->note_id, 
                $chunk->audio_id, 
                [$chunk->id]
            );

            return response()->json([
                'ai_text' => $chunk->ai_text,
                'active_version' => 'ai',
                'message' => 'Chunk processed with AI successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'AI processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified chunk.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $chunk = Chunk::with(['note', 'audioFile'])
                      ->where('id', $id)
                      ->whereHas('note', function ($query) use ($request) {
                          $query->where('user_id', $request->user()->id);
                      })
                      ->firstOrFail();
        
        return response()->json($chunk);
    }
}
