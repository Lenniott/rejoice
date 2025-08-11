<?php
namespace App\Services;
use App\Models\AudioFile;

/**
 * Internal processing units (for devs/agents):
 * - Audio Segment: transient slices to fit model limits (NOT stored in DB).
 * - Transcription Chunk: text units with order/timestamps (stored in 'chunks').
 * - Embedding Window: internal text windows for embeddings (NOT user-facing).
 */
interface TranscriptionService {
    /** @return array<int, array{order:int,start_ms:int,end_ms:int,ai_text:string}> */
    public function transcribeAudioFile(AudioFile $audio): array;
}
