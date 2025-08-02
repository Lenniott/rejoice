<?php

/**
 * VectorizeContentJob - Background job for content vectorization
 * 
 * Requirements:
 * - Process text content for vectorization in background queue
 * - Handle job failures gracefully with retry logic
 * - Integrate with VectorService for actual vectorization
 * - Log processing results and errors for monitoring
 * - Support both audio-linked and text-only content
 * 
 * Flow:
 * - Job queued with content details -> VectorService vectorizes -> Database/Qdrant updated -> Log results
 */

namespace App\Jobs;

use App\Models\Note;
use App\Models\AudioFile;
use App\Models\Chunk;
use App\Services\VectorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Exception;

class VectorizeContentJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * The note ID to vectorize content for
     */
    public string $noteId;

    /**
     * The audio file ID (optional)
     */
    public ?string $audioId;

    /**
     * The text content to vectorize
     */
    public string $content;

    /**
     * Array of chunk IDs included in this content
     */
    public array $chunkIds;

    /**
     * The number of times the job may be attempted
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job should run
     */
    public int $timeout = 300; // 5 minutes for vectorization

    /**
     * Create a new job instance
     *
     * @param string $noteId UUID of the note
     * @param string|null $audioId UUID of the audio file (optional)
     * @param string $content Text content to vectorize
     * @param array $chunkIds Array of chunk UUIDs included
     */
    public function __construct(string $noteId, ?string $audioId, string $content, array $chunkIds = [])
    {
        $this->noteId = $noteId;
        $this->audioId = $audioId;
        $this->content = $content;
        $this->chunkIds = $chunkIds;
    }

    /**
     * Execute the job
     */
    public function handle(VectorService $vectorService): void
    {
        Log::info('VectorizeContentJob: Job started', [
            'note_id' => $this->noteId,
            'audio_id' => $this->audioId,
            'content_length' => strlen($this->content),
            'chunk_count' => count($this->chunkIds)
        ]);

        try {
            // Verify note exists
            $note = Note::find($this->noteId);
            if (!$note) {
                Log::error('VectorizeContentJob: Note not found', ['note_id' => $this->noteId]);
                $this->fail('Note not found');
                return;
            }

            // Verify audio file exists if provided
            if ($this->audioId) {
                $audioFile = AudioFile::find($this->audioId);
                if (!$audioFile) {
                    Log::error('VectorizeContentJob: Audio file not found', [
                        'note_id' => $this->noteId,
                        'audio_id' => $this->audioId
                    ]);
                    $this->fail('Audio file not found');
                    return;
                }
            }

            // Verify chunks exist if provided
            if (!empty($this->chunkIds)) {
                $existingChunks = Chunk::whereIn('id', $this->chunkIds)->count();
                if ($existingChunks !== count($this->chunkIds)) {
                    Log::warning('VectorizeContentJob: Some chunks not found', [
                        'note_id' => $this->noteId,
                        'expected_chunks' => count($this->chunkIds),
                        'found_chunks' => $existingChunks
                    ]);
                }
            }

            // Perform vectorization
            $result = $vectorService->vectorizeContent(
                $this->noteId,
                $this->audioId,
                $this->content,
                $this->chunkIds
            );

            if ($result['success']) {
                Log::info('VectorizeContentJob: Vectorization completed successfully', [
                    'note_id' => $this->noteId,
                    'audio_id' => $this->audioId,
                    'vectors_created' => $result['vectors_created'] ?? 0,
                    'segments_processed' => $result['segments_processed'] ?? 0,
                    'skipped' => $result['skipped'] ?? false
                ]);
            } else {
                Log::error('VectorizeContentJob: Vectorization failed', [
                    'note_id' => $this->noteId,
                    'audio_id' => $this->audioId,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);

                // If this is the final attempt, log but don't fail the job completely
                if ($this->attempts() >= $this->tries) {
                    Log::error('VectorizeContentJob: Max attempts reached, giving up', [
                        'note_id' => $this->noteId,
                        'audio_id' => $this->audioId,
                        'attempts' => $this->attempts()
                    ]);
                } else {
                    // Release the job back to queue for retry
                    $this->release(120); // Retry after 2 minutes
                }
            }

        } catch (Exception $e) {
            Log::error('VectorizeContentJob: Job failed with exception', [
                'note_id' => $this->noteId,
                'audio_id' => $this->audioId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            // If this is the final attempt, fail the job
            if ($this->attempts() >= $this->tries) {
                $this->fail($e);
            } else {
                // Release the job back to queue for retry with exponential backoff
                $delay = min(600, 120 * pow(2, $this->attempts() - 1)); // Cap at 10 minutes
                $this->release($delay);
            }
        }
    }

    /**
     * Handle a job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('VectorizeContentJob: Job permanently failed', [
            'note_id' => $this->noteId,
            'audio_id' => $this->audioId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Optionally, you could:
        // - Mark the content as "vectorization failed"
        // - Send notifications about the failure
        // - Clean up any partial data
    }

    /**
     * Get the middleware the job should pass through
     */
    public function middleware(): array
    {
        // Prevent overlapping jobs for the same note/audio combination
        $key = $this->audioId ? "note:{$this->noteId}:audio:{$this->audioId}" : "note:{$this->noteId}";
        
        return [
            new WithoutOverlapping($key)
        ];
    }

    /**
     * Get the tags that should be assigned to the job
     */
    public function tags(): array
    {
        $tags = ['vectorization', 'note:' . $this->noteId];
        
        if ($this->audioId) {
            $tags[] = 'audio:' . $this->audioId;
        }
        
        if (!empty($this->chunkIds)) {
            $tags[] = 'chunks:' . count($this->chunkIds);
        }
        
        return $tags;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job
     */
    public function backoff(): array
    {
        return [120, 240, 480]; // 2 min, 4 min, 8 min
    }
}
