# Workflows and Screens – ReJoIce (AI Voice Note App)

## 1. Overview

The application centers around **Notes** (containers) and **Audio Files** (recordings). Each recording produces **chunks** for editing and playback. Users can create notes, capture audio, review/edit text, and perform semantic searches across notes.

---

## 2. Core Screens

### 2.1 Note List Screen
**Purpose:** Manage all notes (create, rename, delete, search).  

**UI Elements:**
- Search bar (filter by title)
- List of notes (title + created date)
- “Create Note” button
- Multi-select checkboxes (for delete)

**User Flows:**
1. **Create Note**
   - Tap “Create Note”
   - System creates new note (auto-titled with timestamp)
   - Redirects to Note Editor

2. **Rename Note**
   - Tap note title → inline edit
   - Save sends PATCH to `/api/notes/{id}`

3. **Delete Notes**
   - Select one or more → press “Delete”
   - System cascades deletion (DB, filesystem, Qdrant)

---

### 2.2 Note Editor Screen
**Purpose:** Record, view, edit, and manage chunks of text and audio.

**UI Elements:**
- Record/Stop button
- Live dictation display (Web Speech API)
- AI Process button (manual trigger)
- Text editor with block-based chunks
- Playback controls (per chunk)


**User Flows:**
1. **Record → Stop**
   - Press Record → audio capture begins
   - Live transcription appears in real time
   - Stop → Save audio + dictation chunk
   - AI processing queued (manual or auto)

2. **Edit Text**
   - Click chunk → edit in text editor
   - Saves as `edited_text` and switches active version to “edited”

3. **Play Audio**
   - Press Play → plays chunk-specific segment


---

### 2.3 Search Screen
**Purpose:** Semantic search across all notes and chunks.

**UI Elements:**
- Search bar (natural language input)
- Results list (chunk preview, note reference)
- Multi-select results → “Create Note from Selection”

**User Flows:**
1. **Semantic Search**
   - Enter query → embed via Gemini → search Qdrant
   - Results show relevant chunks with source notes

2. **Create New Note from Results**
   - Select multiple chunks → create composite note

---

## 3. Workflow Diagrams (Conceptual)

### Recording Workflow

Record → Dictation (browser) → Save Audio + Text →
AI Enhancement (Gemini) →
Vectorization (manual/queued) →
Searchable Chunks in Qdrant

### Failure Handling (Lossless)

Audio Saved
├─ Dictation fails → Later AI processing possible
├─ AI fails → Dictation remains editable
└─ Vectorization fails → Manual re-run available

---

## 4. States & Visual Cues

- **Dictation Text:** Light gray label (“Dictation”)  
- **AI Processed Text:** Blue label (“AI”)  
- **Edited Text:** Green label (“Edited”)  
- **Failed AI:** Red badge + “Retry” button  
- **Audio Missing:** Disabled playback icon

---

## 5. Navigation Structure

- `/` → Note List
- `/notes/:id` → Note Editor
- `/search` → Search Screen

---

## 6. Progressive Enhancement

- Core features (record, edit, search) work offline except AI/vectorization.
- AI/vectorization queued when offline and processed on reconnect.

