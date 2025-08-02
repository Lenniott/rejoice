# MVP Decisions – ReJoIce (AI Voice Note App)

## 1. Tech Stack

**Backend Framework:** Laravel 12 (PHP)  
- Handles API endpoints, database, authentication, storage, and job queues.  
- Laravel Breeze (API mode) used for React SPA + API auth scaffolding.  
- Built-in Laravel queue system (Horizon) for background AI/vectorization tasks.

**Frontend Framework:** React (via Laravel Breeze)  
- Built with Vite (default in Laravel).  
- TailwindCSS for UI styling.  
- Text editor for content editing.  

**Databases:**
- **SQLite** – relational data (notes, audio metadata, chunks).  
- **Qdrant** – vector database for semantic search (embeddings).  

**AI/Embedding:**
- **Google Gemini 2.5 Flash** – used for transcription refinement + embedding generation.

**Containerization:**  
- Docker + Docker Compose:
  - Single container builds frontend with Vite, serves via Laravel.  
  - SQLite + Qdrant as separate containers with volumes for persistence.

---

## 2. Audio Storage

**Strategy:** Store audio on filesystem (Laravel storage) with DB reference.  
- Path pattern: `storage/app/audio/{note_id}/{uuid}.webm`  
- SQLite `audio_files` table stores `id`, `note_id`, `path`, `created_at`.  

**Reasoning:** Avoids DB bloat and improves performance for playback/streaming.

---

## 3. Vector Embedding Approach

### **Granularity**
- Vectorize **audio-file level text**, not individual chunks.
- If text > 300 words → split into **300-word segments with 50-word overlaps**.

### **Metadata**
Each vector stored in Qdrant includes:
```json
{
  "note_id": "uuid",
  "audio_id": "uuid",
  "chunk_ids": ["uuid1","uuid2"],
  "source_text": "text of segment",
  "created_at": "timestamp"
}

Re-Embedding via Diff
	•	Compare current transcript to last embedded transcript using Levenshtein similarity.
	•	If difference > 20%, enqueue re-embedding.

Pseudocode:

function shouldReembed($oldText, $newText, $threshold = 0.2) {
    $distance = levenshtein($oldText, $newText);
    $maxLength = max(strlen($oldText), strlen($newText));
    $similarity = $distance / $maxLength;
    return $similarity > $threshold;
}


⸻

4. Deletion Cascade

Deleting Notes must remove:
	•	All chunks from Postgres
	•	All associated audio metadata
	•	All audio files on disk
	•	All embeddings in Qdrant referencing note_id

Pseudocode:

function deleteNote($noteId) {
    DB::transaction(function () use ($noteId) {
        $audioFiles = AudioFile::where('note_id', $noteId)->get();
        foreach ($audioFiles as $file) {
            Storage::delete($file->path); // delete file from disk
        }
        AudioFile::where('note_id', $noteId)->delete();
        Chunk::where('note_id', $noteId)->delete();
        Note::find($noteId)->delete();

        Qdrant::deleteByFilter(['note_id' => $noteId]); // vector deletion
    });
}


⸻

5. Authentication
	•	Breeze API provides token-based auth.
	•	For MVP: single-user mode (preconfigured login credentials).
	•	Future-ready: multi-user expansion requires minimal change.

⸻

6. Deployment
	•	Docker Compose stack:
	•	Laravel app
	•	PostgreSQL
	•	Qdrant
	•	Volumes for Postgres & Qdrant data persistence.

⸻

7. Testing Priorities

Critical Areas
	1.	CRUD Operations
	•	Notes, chunks, audio files
	2.	Delete Cascade
	•	Ensure no orphaned files/vectors remain
	3.	Vector Search
	•	Validate embedding creation and semantic search
	4.	Failover Handling
	•	AI down → dictation fallback
	•	Dictation fails → audio saved
	•	Audio missing → gracefully degrade playback
	5.	Integration
	•	Record → Dictation → AI → Vectorization → Search roundtrip

⸻

8. Frontend Behavior
	•	Note List Screen
	•	Create, rename, delete, search notes
	•	Note Screen
	•	Record audio → live dictation (Web Speech API)
	•	AI enhancement (manual trigger or auto queue)
	•	Chunk editing
	•	Audio playback
	•	Search Screen
	•	Semantic search (Qdrant)
	•	Create note from selected results

⸻

9. Guiding Principles
	•	Lossless Capture: Always store audio even if dictation/AI fails.
	•	Progressive Enhancement: Dictation first, AI refinement optional.
	•	Local-First: Everything runs locally in containers, no cloud storage.

