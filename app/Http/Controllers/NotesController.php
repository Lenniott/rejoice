<?php

/**
 * NotesController - Handles CRUD operations for notes with cascade delete
 * 
 * Requirements:
 * - List all notes for authenticated user
 * - Create new notes with auto-generated titles
 * - Update note titles
 * - Delete notes with cascade removal of audio, chunks, and vectors
 * - Return JSON responses for API consumption
 * 
 * Flow:
 * - GET /api/notes -> Returns list of all user notes
 * - POST /api/notes -> Creates new note, returns note data
 * - PATCH /api/notes/{id} -> Updates note title, returns updated note
 * - DELETE /api/notes/{id} -> Removes note and all related data
 */

namespace App\Http\Controllers;

use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotesController extends Controller
{
    /**
     * Display a listing of the notes.
     */
    public function index(Request $request): JsonResponse
    {
        $notes = Note::where('user_id', $request->user()->id)
                    ->orderBy('created_at', 'desc')
                    ->get();
        
        return response()->json($notes);
    }

    /**
     * Store a newly created note in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $title = $request->input('title') ?? 'Note - ' . now()->format('Y-m-d H:i');

        $note = Note::create([
            'user_id' => $request->user()->id,
            'title' => $title,
        ]);

        return response()->json($note, 201);
    }

    /**
     * Update the specified note in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $note = Note::where('id', $id)
                    ->where('user_id', $request->user()->id)
                    ->firstOrFail();
                    
        $note->update([
            'title' => $request->input('title'),
        ]);

        return response()->json($note);
    }

    /**
     * Remove the specified note from storage.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $note = Note::where('id', $id)
                    ->where('user_id', $request->user()->id)
                    ->firstOrFail();

        // Use transaction to ensure all related data is removed atomically
        DB::transaction(function () use ($note) {
            // Delete associated audio files (this will trigger cascade deletes)
            $note->audioFiles()->delete();
            
            // Delete associated chunks
            $note->chunks()->delete();
            
            // Delete the note itself
            $note->delete();
        });

        return response()->json(['message' => 'Note deleted']);
    }
}
