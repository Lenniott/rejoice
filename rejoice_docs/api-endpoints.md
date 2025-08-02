# API Endpoints – ReJoIce (AI Voice Note App)

## 1. Overview

All endpoints are **RESTful** under `/api`. Responses are JSON. Authentication is handled via Laravel Breeze API tokens (single-user for MVP).

---

## 2. Authentication (✅ Currently Working)

Laravel Breeze provides these authentication endpoints:

### 2.1 Web Authentication Routes
- `GET /login` - Login page
- `POST /login` - Submit login credentials  
- `GET /register` - Registration page
- `POST /register` - Create new account
- `GET /forgot-password` - Password reset request page
- `POST /forgot-password` - Send password reset email
- `GET /reset-password/{token}` - Password reset form
- `POST /reset-password` - Submit new password
- `POST /logout` - Logout user

### 2.2 Protected Pages
- `GET /dashboard` - Main authenticated area
- `GET /profile` - User profile management
- `PATCH /profile` - Update profile
- `DELETE /profile` - Delete account
- `PUT /password` - Update password

**Status**: ✅ All authentication flows fully functional and tested

---

## 3. Notes (🔄 Planned - Phase 2)

### 3.1 List Notes
**GET** `/api/notes`

**Response:**
```json
[
  {
    "id": "uuid",
    "title": "Note - 2025-07-31 14:23",
    "created_at": "2025-07-31T14:23:00Z",
    "updated_at": "2025-07-31T14:23:00Z"
  }
]
```

---

### 3.2 Create Note

**POST** `/api/notes`

**Body:**

```json
{ "title": "Optional title" }
```

**Response:**

```json
{ "id": "uuid", "title": "Note - 2025-07-31 14:23" }
```

---

### 3.3 Rename Note

**PATCH** `/api/notes/{id}`

**Body:**

```json
{ "title": "New title" }
```

**Response:**

```json
{ "id": "uuid", "title": "New title" }
```

---

### 3.4 Delete Note

**DELETE** `/api/notes/{id}`

**Response:**

```json
{ "message": "Note deleted" }
```

Cascade deletes associated audio, chunks, and vectors.

---

## 3. Audio & Chunks

### 3.1 Upload Audio & Dictation

**POST** `/api/notes/{noteId}/audio`

**Body (multipart/form-data):**

* `audio` (file: webm)
* `dictation_text` (string)

**Response:**

```json
{
  "audio_id": "uuid",
  "chunk_id": "uuid",
  "dictation_text": "transcribed text"
}
```

---

### 3.2 Edit Chunk

**PATCH** `/api/chunks/{id}`

**Body:**

```json
{ "edited_text": "Corrected text", "active_version": "edited" }
```

---

### 3.3 AI Process Chunk

**POST** `/api/chunks/{id}/ai-process`

**Response:**

```json
{ "ai_text": "Refined AI output", "active_version": "ai" }
```

---

## 4. Search & Vectorization

### 4.1 Semantic Search (Dual-Level)

**POST** `/api/search/semantic`

**Body:**

```json
{ "query": "Find notes about taxes" }
```

**Response:**

```json
{
  "chunk_results": [
    {
      "note_id": "uuid",
      "chunk_ids": ["uuid1"],
      "text_preview": "Refined AI output ...",
      "score": 0.89,
      "source_type": "chunk"
    }
  ],
  "note_results": [
    {
      "note_id": "uuid",
      "note_title": "Tax Planning Guide",
      "text_preview": "Complete tax information...",
      "score": 0.76,
      "source_type": "note"
    }
  ]
}
```

---

### 4.2 Find Similar Notes

**GET** `/api/notes/{id}/similar`

**Query Parameters:**
* `limit` (optional, default: 5)

**Response:**

```json
{
  "similar_notes": [
    {
      "note_id": "uuid",
      "note_title": "Related Tax Notes",
      "similarity_score": 0.82,
      "preview": "Contains similar tax topics..."
    }
  ]
}
```

---

### 4.3 Trigger Re-Embedding

**POST** `/api/vectorize/run`

**Body (optional):**

```json
{ 
  "note_id": "uuid",
  "type": "note-level"
}
```

Re-embeds chunks/audio and/or note-level vectors.

**Response:**

```json
{ "message": "Vectorization started" }
```

---

### 4.4 Vectorize Specific Note

**POST** `/api/notes/{id}/vectorize`

**Response:**

```json
{ "message": "Note vectorization queued" }
```

---

## 5. Error Handling

* 400 Bad Request → Invalid payload
* 404 Not Found → Missing resource
* 500 Server Error → AI/Vectorization failure (log and fallback)

---

## 6. Authentication

* Single-user API token (stored in `.env`)
* Include `Authorization: Bearer <token>` in requests