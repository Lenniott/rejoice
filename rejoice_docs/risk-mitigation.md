# Risk Mitigation – ReJoIce (AI Voice Note App)

## 1. Core Principle

**Lossless Capture** – No matter what fails (browser dictation, AI processing, storage, network), the raw audio must always be saved and recoverable for later processing. This prevents “thought loss” due to technical issues.

---

## 2. Failure Scenarios & Fallbacks

### 2.1 Browser Dictation Fails
- **Cause**: Mic permission denied, Web Speech API unsupported, browser crash.
- **Fallback**:
  - Record audio locally without transcription.
  - Display “dictation unavailable” but allow later AI/manual processing.

### 2.2 AI Processing Fails
- **Cause**: Gemini API down, quota exceeded, malformed request.
- **Fallback**:
  - Preserve raw dictation text.
  - Mark chunk as “AI pending/failed”.
  - Provide “Retry AI Process” button in UI.

### 2.3 Audio File Corruption/Missing
- **Cause**: Disk error, accidental deletion, failed write.
- **Fallback**:
  - Retain dictation text (still usable for AI and search).
  - UI disables playback, shows error badge.

### 2.4 Vectorization/Embedding Fails
- **Cause**: Qdrant unavailable, network timeout, API failure.
- **Fallback**:
  - Search falls back to title/text search.
  - Flag vectors for retry when Qdrant is back online.

### 2.5 Sync Failures (DB vs Filesystem vs Qdrant)
- **Cause**: Partial delete, rollback errors.
- **Fallback**:
  - Implement delete tests (see below).
  - Scheduled consistency check to remove orphans.

---

## 3. Testing Focus Areas

### 3.1 Delete Cascade
- **Goal**: Ensure deletions propagate across all systems:
  - Postgres (notes, chunks, audio metadata)
  - Filesystem (audio files)
  - Qdrant (vectors)
- **Test**: Create → Delete note → Verify no residual files/vectors.

### 3.2 Data Integrity
- **Goal**: Prevent orphan records and mismatches.
- **Test**: Simulate failures during CRUD and confirm rollback integrity.

### 3.3 Fallback Logic
- **Goal**: Verify system behavior under failure conditions.
- **Test Cases**:
  - AI down → dictation remains functional.
  - Dictation fails → audio saved for later processing.
  - Audio missing → UI displays error state, search still works.

### 3.4 Performance Degradation
- **Goal**: Ensure smooth UX even when some subsystems fail.
- **Test**: Stress test with high number of vectors, verify UI responsiveness.

---

## 4. Recovery & Manual Overrides

- **Manual AI Trigger**: User can manually re-run AI processing on chunks or notes.
- **Manual Vectorization**: User can manually trigger vectorization after recovery from failure.
- **Retry Queues**: Failed AI/vectorization jobs remain in a retryable queue.

---

## 5. Developer Safeguards

- **Transactions**: Use DB transactions to ensure atomic operations.
- **Background Workers**: Handle AI and vector tasks outside request lifecycle.
- **Logging**: Detailed logs for AI, dictation, storage, and search failures.
- **Health Checks**: Monitor status of Qdrant, Postgres, and file storage.

---

## 6. User Experience for Failures

- **Visual Indicators**:
  - AI failed: Red badge + retry button.
  - Dictation unavailable: Fallback message.
  - Audio missing: Disabled playback icon.
- **Non-Blocking**: Failures never prevent note creation or editing.
- **History Preservation**: Even failed attempts are recorded (debug and recovery).