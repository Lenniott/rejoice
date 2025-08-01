# Product Requirements Document – ReJoIce (AI Voice Note App)

**Version:** 2.0  
**Date:** 31/07/2025 (Revised for Laravel + React)  
**Author:** ReJoIce Dev Team  

---

## 1. Introduction

### 1.1 Overview
ReJoIce is a **local-first AI-enhanced voice note application** designed to capture spoken thoughts, transcribe them, and organize them for semantic search and retrieval. The MVP emphasizes **lossless capture** — ensuring no data is lost even if transcription or AI processing fails.

### 1.2 Goals
- **Instant capture**: Record audio with live dictation.
- **Progressive enhancement**: AI refines transcription asynchronously or manually.
- **Powerful search**: Semantic search across notes using vector embeddings.
- **Local privacy**: All data (audio, text, embeddings) stored locally.

### 1.3 Target Audience
- Single-user (initially)
- Technically inclined users who value local data privacy and fast, AI-assisted workflows.

---

## 2. System Overview

### 2.1 Core Concepts
- **Notes**: Containers for content, can have multiple recordings (audio files).
- **Chunks**: Editable blocks of text tied to audio or manually created.
- **Vectorization**: Text converted to embeddings for semantic search (Qdrant).
- **Lossless Flow**: Audio always stored; dictation and AI are enhancements, not dependencies.

### 2.2 Technology Stack
- **Backend**: Laravel 12 (PHP) with Breeze API
- **Frontend**: React + TailwindCSS + Vite
- **Database**: PostgreSQL (structured data)
- **Vector DB**: Qdrant (semantic search)
- **AI Model**: Google Gemini 2.5 Flash (transcription refinement + embeddings)
- **Containerization**: Docker + Docker Compose

---

## 3. Functional Requirements

### 3.1 Notes Management
- Create note (auto timestamp title)
- Rename note
- Delete note (cascade deletes audio, chunks, vectors)
- List and search notes (title-based)

### 3.2 Recording & Transcription
- Record/stop audio (Web Speech API for dictation)
- Save audio file + dictation text as new chunk
- AI processing (manual trigger or auto-queue)

### 3.3 Editing & Playback
- Text editor for chunks
- Edit chunks (switch active version to “edited”)
- Playback audio per chunk


### 3.4 Semantic Search
- Enter query → vectorize → Qdrant search
- Display related chunks/notes
- Create note from selected results

---

## 4. Non-Functional Requirements

### 4.1 Performance
- Low-latency dictation (browser-side)
- Smooth UI (React + Vite)
- Efficient vectorization batching

### 4.2 Data Persistence
- PostgreSQL for structured data
- Filesystem for audio (storage/app/audio)
- Qdrant for vectors
- Docker volumes for persistence

### 4.3 Reliability
- Lossless: Always save audio, even if transcription/AI fails
- Graceful fallbacks for AI, dictation, and search

### 4.4 Security
- Single-user auth (Laravel Breeze token-based)
- Local data only (no cloud sync in MVP)

---

## 5. API Endpoints (Conceptual)

### Notes
- `GET /api/notes` – List notes
- `POST /api/notes` – Create note
- `PATCH /api/notes/{id}` – Rename note
- `DELETE /api/notes/{id}` – Delete note (cascade)

### Chunks & Audio
- `POST /api/notes/{id}/audio` – Upload audio + dictation text
- `PATCH /api/chunks/{id}` – Edit chunk
- `POST /api/chunks/{id}/ai-process` – AI refine chunk

### Search
- `POST /api/search/semantic` – Query vectors
- `POST /api/vectorize/run` – Trigger re-embedding

---

## 6. Data Model

### Notes
- `id`, `title`, `created_at`, `updated_at`

### Audio Files
- `id`, `note_id`, `path`, `created_at`

### Chunks
- `id`, `note_id`, `audio_id`, `dictation_text`, `ai_text`, `edited_text`, `active_version`

---

## 7. Vectorization Strategy

- Vectorize audio-file text (300-word segments, 50-word overlap)
- Store chunk IDs in vector metadata for search linkage
- Re-embed if >20% text diff vs last embedding

---

## 8. Workflows (Summary)

- Record → Dictation → Save audio/chunk → AI refine → Vectorize → Searchable
- Delete note → cascade delete DB, files, vectors
- Search → select chunks → create new note

---

## 9. Future Enhancements

- Multi-user support
- Offline AI transcription
- Tags and categorization
- Mobile app wrapper (Capacitor or React Native)