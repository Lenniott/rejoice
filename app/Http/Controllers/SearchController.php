<?php

/**
 * SearchController - Handles semantic search operations using dual-level vectorization
 * 
 * Requirements:
 * - Perform semantic search across chunk-level content
 * - Find similar notes using note-level vectors
 * - Return dual-level results (chunks + notes) separately
 * - Handle search queries with proper validation
 * - Return relevance scores and previews for results
 * 
 * Flow:
 * - POST /api/search/semantic -> Searches chunks and notes, returns dual results
 * - GET /api/notes/{id}/similar -> Finds similar notes for specific note
 * - Both endpoints use VectorService for Qdrant operations
 */

namespace App\Http\Controllers;

use App\Models\Note;
use App\Services\VectorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SearchController extends Controller
{
    protected VectorService $vectorService;

    public function __construct(VectorService $vectorService)
    {
        $this->vectorService = $vectorService;
    }

    /**
     * Perform semantic search across chunks and notes.
     */
    public function semanticSearch(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|max:1000',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = $request->input('query');
        $limit = $request->input('limit', 10);

        try {
            // Perform dual-level search using VectorService
            $searchResults = $this->vectorService->searchDualLevel($query, $limit);

            return response()->json([
                'query' => $query,
                'chunk_results' => $searchResults['chunk_results'] ?? [],
                'note_results' => $searchResults['note_results'] ?? [],
                'total_results' => count($searchResults['chunk_results'] ?? []) + count($searchResults['note_results'] ?? []),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Search operation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Find similar notes for a specific note.
     */
    public function findSimilarNotes(Request $request, string $noteId): JsonResponse
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $note = Note::where('id', $noteId)
                    ->where('user_id', $request->user()->id)
                    ->firstOrFail();
        $limit = $request->input('limit', 5);

        try {
            // Find similar notes using VectorService
            $similarNotes = $this->vectorService->findSimilarNotes($noteId, $limit);

            return response()->json([
                'note_id' => $noteId,
                'note_title' => $note->title,
                'similar_notes' => $similarNotes,
                'total_found' => count($similarNotes),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Similar notes search failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
