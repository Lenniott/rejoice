# Data Schemas – ReJoIce (AI Voice Note App)

## 1. Overview
ReJoIce uses **PostgreSQL** for structured note/chunk/audio data and **Qdrant** for vector embeddings used in semantic search.

---

## 2. PostgreSQL Schema

### 2.1 Notes Table
Stores top-level notes.

| Column       | Type       | Constraints     | Description                   |
|--------------|------------|-----------------|-------------------------------|
| id           | UUID       | PK              | Unique note identifier        |
| title        | VARCHAR(255)| NOT NULL       | Note title (timestamp default)|
| created_at   | TIMESTAMP  | NOT NULL        | Creation time                 |
| updated_at   | TIMESTAMP  | NOT NULL        | Last modified time            |

---

### 2.2 Audio Files Table
Stores audio metadata and file references.

| Column     | Type  | Constraints        | Description                        |
|------------|-------|--------------------|------------------------------------|
| id         | UUID  | PK                 | Unique audio file identifier       |
| note_id    | UUID  | FK (notes.id)      | Parent note                        |
| path       | TEXT  | NOT NULL           | Filesystem path to `.webm` file    |
| created_at | TIMESTAMP | NOT NULL       | When recording was made            |

---

### 2.3 Chunks Table
Represents blocks of text (dictation, AI, edited).

| Column        | Type       | Constraints         | Description                              |
|---------------|------------|---------------------|------------------------------------------|
| id            | UUID       | PK                  | Unique chunk identifier                   |
| note_id       | UUID       | FK (notes.id)       | Parent note                               |
| audio_id      | UUID       | FK (audio_files.id) | Optional: linked recording                 |
| dictation_text| TEXT       | Nullable            | Raw dictation from browser API            |
| ai_text       | TEXT       | Nullable            | AI-refined version                        |
| edited_text   | TEXT       | Nullable            | User-edited version                        |
| active_version| VARCHAR(10)| NOT NULL (check)    | 'dictation', 'ai', or 'edited'            |
| chunk_order   | INTEGER    | NOT NULL            | Order of chunk in note                     |
| created_at    | TIMESTAMP  | NOT NULL            | Creation time                              |
| updated_at    | TIMESTAMP  | NOT NULL            | Last modified time                         |

---

## 3. Qdrant Vector Schema

### 3.1 Collection: `voice_notes_v1`

- Stores semantic embeddings for note content (segmented by audio file).

#### Metadata Payload
```json
{
  "note_id": "uuid-of-note",
  "audio_id": "uuid-of-audio-file",
  "chunk_ids": ["uuid1", "uuid2"],
  "source_text": "Concatenated or segmented text",
  "created_at": "2025-07-31T12:00:00Z"
}
````

#### Vector Strategy

* Each **audio file** transcript vectorized (split into 300-word segments with 50-word overlap).
* Re-embed if >20% diff detected between new and old transcript.

---

## 4. Relationships

* `notes` (1) → `audio_files` (many)
* `notes` (1) → `chunks` (many)
* `audio_files` (1) → `chunks` (many, optional)
* Qdrant vectors reference `note_id` and optionally `audio_id` + `chunk_ids`

---

## 5. Indexing

* Postgres: Index on `note_id` for `chunks` and `audio_files`
* Qdrant: Indexed via vector similarity (cosine or dot product)