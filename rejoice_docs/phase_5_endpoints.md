# API Endpoint Specifications (Phase 5) – Full Implementation Detail

This document describes **every endpoint required for Phase 5 MVP**, focusing only on native notes and chunks.

---

## Prerequisites for MVP

* Notes contain only native chunks created from their own audio/transcription.
* No `merged_chunk_ids` support in note creation or updates.
* Chunks table include **start\_time\_seconds / end\_time\_seconds** (int but with with enough granularity we accuratly capture the time in the audio something is being said) for playback alignment.
* Audio files link directly to notes.
* Vectorization supports chunk- and note-level embeddings using 3,072 dimensions.
* `audio_files` table has a `status` column.

---

## Chunks Metadata Changes

### Added Fields for All Chunk Responses

* `start_time_seconds` (float) – Beginning of chunk in audio file.
* `end_time_seconds` (float) – End of chunk in audio file.
* `archived` (boolean) – Marks whether the chunk has been archived (soft-deleted). Archived chunks remain in storage and can be restored later but are excluded from default note retrieval and search.

These fields are essential for:

* Accurate audio playback when selecting a chunk.
* Re-synchronizing transcripts with audio if retriggered.
* Removing unwanted chunks from view/playback in the UI and search results without fully deleting them.

## Audio Metadata Changes

### Add a `status` column to `audio_files` table:

* Values: `processing`, `completed`, `failed`
* Updates:

  * Set `processing` on upload
  * Set `completed` after transcription & chunk creation
  * Set `failed` if transcription job errors
  * Set `pending` if no attempt to transcribe has happened yet.
* Benefits: API can reliably return `status` without extra queries.

---

## 1. Notes Endpoints

### `GET /notes`

**Purpose:** Retrieve a list of notes belonging to the authenticated user.

**Query Parameters:**

* `search` (string, optional)
* `limit` (integer, optional, default=20)
* `offset` (integer, optional, default=0)
* `sort` (string, optional) – Valid values: `created_at`, `updated_at`, `title` (alphabetical). Suffix with `_asc` or `_desc` for direction. Default: `updated_at_desc`.
* `include` (string, optional) – Comma-separated: `chunks`, `audio`, `vectors`.

**Example Request:**

```
GET /notes?search=meeting&limit=10&sort=title_asc&include=chunks HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "data": [
    {
      "id": "note-uuid",
      "title": "Meeting Notes",
      "created_at": "2025-08-01T12:34:56Z",
      "updated_at": "2025-08-02T10:15:00Z",
      "chunk_count": 5
    }
  ]
}

```

---

### `POST /notes`

**Purpose:** Create a new note.

**Body Parameters:**

* `title` (string, optional)
* `audio_file_id` (UUID, optional) – If provided, links an existing uploaded audio file to the new note. The system will associate the file with this note and trigger transcription and chunk generation from that audio. If omitted, a blank note is created.

**Example Request:**

```
POST /notes HTTP/1.1
Authorization: Bearer <token>
Content-Type: application/json

{
  "title": "Budget Highlights",
  "audio_file_id": "audio-uuid"
}

```

**Example Response:**

```
{
  "data": {
    "id": "note-uuid",
    "title": "Budget Highlights",
    "audio_file_id": "audio-uuid",
    "created_at": "2025-08-01T12:34:56Z"
  }
}

```

---

### `GET /notes/:id`

**Purpose:** Retrieve full details of a note with chunks and playback metadata.

**Query Parameters:**

* `include_versions` (boolean, default=false) – By default only the active text version of each chunk is returned for display performance; set this to true to retrieve all versions (dictation, AI, edited) typically used for exports or administrative review.

**Example Request:**

```
GET /notes/note-uuid?include_versions=true HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "data": {
    "id": "note-uuid",
    "title": "Meeting Notes",
    "chunks": [
      {
        "id": "chunk-uuid-1",
        "active_version": "ai",
        "ai_text": "AI processed text",
        "dictation_text": "raw dictation",
        "edited_text": "final edit",
        "chunk_order": 1,
        "start_time_seconds": 12.5,
        "end_time_seconds": 24.8
      }
    ],
    "audio_files": [
      { "id": "audio-uuid", "duration": 120, "path": "..." }
    ]
  }
}

```

---

### `PUT /notes/:id`

**Purpose:** Update note metadata (e.g., note title or other top‑level fields like description in future expansions; does not modify chunk content or audio associations). 

**Example Request:**

```
PUT /notes/note-uuid HTTP/1.1
Authorization: Bearer <token>
Content-Type: application/json

{
  "title": "Updated Project Brief"
}

```

**Example Response:**

```
{
  "data": {
    "id": "note-uuid",
    "title": "Updated Project Brief",
    "updated_at": "2025-08-05T09:30:00Z"
  }
}

```

---

### `DELETE /notes/:id`

**Purpose:** Permanently remove a note. Deletion removes the note record itself and cascades to associated data: its chunks, related audio files, and any note- or chunk-level vector embeddings. This is a hard delete; no soft-delete or recovery is available, and all linked content is purged immediately.

**Example Request:**

```
DELETE /notes/note-uuid HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "status": "success",
  "message": "Note deleted successfully"
}

```

---

### `POST /notes/:id/vectorize`

**Purpose:** Manually trigger note-level vectorization. This endpoint is used when the note has new audio or chunks added since its last vectorization or has never been vectorized. It aggregates all AI‑processed chunk text into a single embedding (3,072 dimensions) for use in related‑note search and linking.

**Behavior:**

* Aggregates all AI‑processed chunk text from the note.
* Generates or updates the note-level vector embedding in Qdrant.
* Intended for manual use (e.g., prompted when leaving a note or via a UI “Re-index” button). Automatic chunk-level vectorization occurs separately.

**Example Request:**

```
POST /notes/note-uuid/vectorize HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "status": "success",
  "message": "Note vectorization complete"
}

```

---

## 2. Audio Files Endpoints

Audio files represent the raw recordings captured or uploaded by the user. Each file is directly linked to a note and is used to generate transcription chunks. These endpoints manage the full lifecycle of audio files, including upload, retrieval of metadata (such as file path for playback), and deletion. Linking audio directly to notes ensures that playback and transcription are always in sync and simplifies re‑processing if needed.

### `POST /audio`

**Purpose:** Upload audio file and initiate transcription.

**Example Request:**

```
POST /audio HTTP/1.1
Authorization: Bearer <token>
Content-Type: multipart/form-data

[file contents here]

```

**Example Response:**

```
{
  "data": {
    "id": "audio-uuid",
    "note_id": "note-uuid",
    "duration": 300,
    "status": "transcribing"  // statuses: `processing` (processing audio), `completed` (transcription done and chunks generated), `failed` (error during processing)
  }
}

```

---

### `GET /audio/:id`

**Purpose:** Retrieve audio metadata or file path for playback.

**Example Request:**

```
GET /audio/audio-uuid HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "data": {
    "id": "audio-uuid",
    "duration": 300,
    "path": "https://storage/...",
    "note_id": "note-uuid"
  }
}

```

---

### `DELETE /audio/:id`

**Purpose:** Permanently remove audio and associated transcription chunks & vectors. Deletion cascades to any chunks generated from the file, ensuring no orphan data remains.

**Example Request:**

```
DELETE /audio/audio-uuid HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "status": "success",
  "message": "Audio deleted successfully"
}

```

---

## 3. Chunks Endpoints

### `GET /chunks/:id`

**Purpose:** Retrieve all versions of a chunk and playback metadata. Soft‑deleted chunks are excluded unless explicitly requested via query parameters.

**Query Parameters:**

* `include_deleted` (boolean, optional, default=false) – If true, returns the chunk even if it is soft‑deleted (for archive/admin views).

**Example Request:**

```
GET /chunks/chunk-uuid HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "data": {
    "id": "chunk-uuid",
    "dictation_text": "Original spoken text",
    "ai_text": "Processed AI text",
    "edited_text": "User edited text",
    "active_version": "edited",
    "chunk_order": 5,
    "start_time_seconds": 45.2,
    "end_time_seconds": 58.9,
    "is_deleted": false
  }
}

```

---

### `POST /chunks/:id/archive`

**Purpose:** Archive a chunk (soft delete). The chunk record and its vector embedding remain stored but are flagged as archived. Archived chunks are excluded from default searches and note views but can be restored later.

**Behavior:**

* Sets `archived = true` in database.
* Updates Qdrant vector payload to `{ "archived": true }` so search filters exclude it.
* Does **not** delete the associated audio file (other chunks may reference it).

**Example Request:**

```
POST /chunks/chunk-uuid/archive HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "status": "success",
  "message": "Chunk archived successfully"
}

```

---

### `POST /chunks/:id/restore`

**Purpose:** Restore a previously archived chunk. This resets the `archived` flag in both the database and Qdrant payload, making the chunk visible in search and playback again.

**Example Request:**

```
POST /chunks/chunk-uuid/restore HTTP/1.1
Authorization: Bearer <token>

```

**Example Response:**

```
{
  "status": "success",
  "message": "Chunk restored successfully"
}

```

---

## 4. Vectorisation & Search Endpoints

### `GET /search`

**Purpose:** Perform semantic search across chunks or notes.

**Example Response:**

```
{
  "data": [
    {
      "type": "chunk",
      "id": "chunk-uuid-2",
      "score": 0.87,
      "snippet": "AI processed text about budget highlights"
    },
    {
      "type": "note",
      "id": "note-uuid-4",
      "score": 0.76,
      "title": "Budget Review Summary"
    }
  ]
}

```

---

## Implementation Notes (Updates)

* **Chunk Order:** UI should render chunks sorted by `chunk_order`.
* **Timestamps:** `start_time_seconds` and `end_time_seconds` used for precise playback of relevant audio segment.
* **Audio Storage Purpose:** Even after transcription, audio is stored both for re-transcription fallback and for inline playback within the UI.
* **Search + Playback:** Search results must include chunk IDs and allow playback via `origin_note_id` and timestamps.
* **Composite Note Behavior:** All notes can mix native and foreign chunks; `note_chunks` join table ensures flexible linking and prevents duplication.
* **Vectorization Details:** Both chunk and note embeddings use 3,072-dimensional vectors. Chunk vectors remain tied to their origin note; note vectors aggregate all chunk text for linking.
* **Error Handling:** Standard JSON\:API errors: 401 Unauthorized, 404 Not Found, 422 Validation Failed, 429 Rate Limit.
* **Rate Limiting:** Respect Gemini API caps (30,000 tokens/minute, 100 requests/minute) for background vectorization queues.
