Here’s a **documentation draft** for the **note-level vectorization pipeline**, aligned with the existing project docs and architecture:

---

# Note-Level Vectorization Pipeline – ReJoIce

## 1. Overview

The ReJoIce app supports **dual-level vector embeddings** for semantic search:

1. **Chunk-level vectors** – Fine-grained embeddings for specific text segments.
2. **Note-level vectors** – Aggregated embeddings representing the entire note.

This dual approach enables:

* **Precise searches** (chunk-level) for granular insights.
* **Contextual searches** (note-level) to identify broader themes across notes.
* **Composite note creation** by merging relevant chunks while maintaining original context.

---

## 2. Data Flow

### 2.1 Trigger Points

* On **note creation or update** (title or chunk changes).
* On **manual re-embedding** via `/api/vectorize/run`.
* On **scheduled maintenance** (e.g., nightly re-check of significant changes).

### 2.2 Pipeline Steps

1. **Aggregate Note Content**

   * Concatenate all active chunks (`dictation`, `ai`, or `edited`) for the note.
   * Respect chunk order (`chunk_order`).

2. **Preprocess Text**

   * Normalize whitespace, remove artifacts.
   * Apply 300-word segmentation with 50-word overlap **if text exceeds limit**.

3. **Generate Embeddings (Gemini 2.5 Flash)**

   * Use `models/embedding-001` for 768-dim vectors.
   * Send batched segments to minimize API calls.

4. **Store in Qdrant**

   * Create or update entry in `vector_embeddings` table:

     * `note_id`
     * `chunk_ids` (empty for pure note-level)
     * `source_text` (concatenated note text)
     * `text_hash` (SHA-256 for diff detection)
     * `qdrant_point_id` (reference to Qdrant point)

5. **Change Detection**

   * Compare new `text_hash` with last stored hash.
   * Re-embed if **>20% difference** (Levenshtein distance).

---

## 3. Database Schema Integration

### 3.1 Vector Embeddings Table

Defined in [`database-schema.txt`](./database-schema.txt):

* **Key Fields for Note-Level**:

  * `note_id` (UUID, required)
  * `audio_id` (NULL)
  * `chunk_ids` (empty array `[]`)
  * `source_text` (concatenated note text)
  * `text_hash` (SHA-256 for re-embedding checks)

Chunk-level embeddings populate `audio_id` and `chunk_ids`; note-level embeddings omit them.

---

## 4. API Endpoints

### 4.1 Trigger Re-Embedding

`POST /api/vectorize/run`

**Body (optional):**

```json
{ "note_id": "uuid" }
```

* If `note_id` provided → re-embed single note.
* If omitted → re-embed all stale notes (diff >20%).

### 4.2 Semantic Search

`POST /api/search/semantic`

**Body:**

```json
{ "query": "Find project summaries about AI models" }
```

**Response:**

* Returns both chunk-level and note-level matches, ranked by similarity.

---

## 5. Search Strategy

* Default: Show **chunk-level matches** for precision.
* Option: Aggregate **note-level matches** for context (e.g., group results by note).
* UI: Indicate source (chunk vs note) in search results.

---

## 6. Failure Handling

* If note-level embedding fails:

  * Fallback to chunk-level embeddings for search.
  * Retry on next `/api/vectorize/run`.

See [risk-mitigation.md](./risk-mitigation.md) for detailed fallback scenarios.

---

## 7. WebSocket Updates

* Broadcast **`VectorizationCompleted`** event after note-level embedding completes.
* UI listens to update semantic search results in real time ([websockets.md](./websockets.md)).

---

## 8. Testing

* **Unit tests**:

  * Verify hash change detection logic.
  * Ensure correct note text aggregation order.
* **Integration tests**:

  * Validate Qdrant insertion and retrieval.
  * Confirm chunk vs note-level search results are distinguishable.

---

## 9. Future Enhancements

* Multi-user support: namespace embeddings per user.
* On-device embedding for offline-first mode.
* Weighted search: combine chunk + note scores for hybrid ranking.

