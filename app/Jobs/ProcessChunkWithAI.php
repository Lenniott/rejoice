<?php

/**
 * ProcessChunkWithAI Job - Background processing for AI text enhancement
 * 
 * Requirements:
 * - Process chunks with AI enhancement in background queue
 * - Handle job failures gracefully with retry logic
 * - Update chunk database records after successful processing
 * - Log processing results and errors for monitoring
 * - Fail gracefully when AI service is unavailable
 * 
 * Flow:
 * - Job queued with chunk ID -> AIService enhances text -> Update chunk in database -> Log results
 */

namespace App\Jobs;

use App\Models\Chunk;
use App\Services\AIService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessChunkWithAI implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * The chunk ID to process
     */
    public string $chunkId;

    /**
     * The number of times the job may be attempted
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job should run
     */
    public int $timeout = 120;

    /**
     * Create a new job instance
     *
     * @param string $chunkId UUID of the chunk to process
     */
    public function __construct(string $chunkId)
    {
        $this->chunkId = $chunkId;
    }

    /**
     * Execute the job
     */
    public function handle(AIService $aiService): void
    {
        Log::info('ProcessChunkWithAI: Job started', ['chunk_id' => $this->chunkId]);

        try {
            // Check if chunk exists
            $chunk = Chunk::find($this->chunkId);
            if (!$chunk) {
                Log::error('ProcessChunkWithAI: Chunk not found', ['chunk_id' => $this->chunkId]);
                $this->fail('Chunk not found');
                return;
            }

            // Check if AI service is configured
            if (!$aiService->isConfigured()) {
                Log::warning('ProcessChunkWithAI: AI service not configured, skipping processing', [
                    'chunk_id' => $this->chunkId
                ]);
                return;
            }

            // Check if chunk already has AI text and we're not overriding
            if (!empty($chunk->ai_text) && !$this->shouldOverrideExisting()) {
                Log::info('ProcessChunkWithAI: Chunk already has AI text, skipping', [
                    'chunk_id' => $this->chunkId
                ]);
                return;
            }

            // Process the chunk
            $success = $aiService->processChunk($this->chunkId);

            if ($success) {
                Log::info('ProcessChunkWithAI: Job completed successfully', [
                    'chunk_id' => $this->chunkId
                ]);
            } else {
                Log::error('ProcessChunkWithAI: Processing failed', [
                    'chunk_id' => $this->chunkId
                ]);
                
                // If this is the final attempt, log but don't fail the job
                if ($this->attempts() >= $this->tries) {
                    Log::error('ProcessChunkWithAI: Max attempts reached, giving up', [
                        'chunk_id' => $this->chunkId,
                        'attempts' => $this->attempts()
                    ]);
                } else {
                    // Release the job back to queue for retry
                    $this->release(60); // Retry after 60 seconds
                }
            }

        } catch (Exception $e) {
            Log::error('ProcessChunkWithAI: Job failed with exception', [
                'chunk_id' => $this->chunkId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            // If this is the final attempt, fail the job
            if ($this->attempts() >= $this->tries) {
                $this->fail($e);
            } else {
                // Release the job back to queue for retry with exponential backoff
                $delay = min(300, 60 * pow(2, $this->attempts() - 1)); // Cap at 5 minutes
                $this->release($delay);
            }
        }
    }

    /**
     * Handle a job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('ProcessChunkWithAI: Job permanently failed', [
            'chunk_id' => $this->chunkId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Optionally, you could mark the chunk as "AI processing failed" 
        // or send notifications about the failure
    }

    /**
     * Get the middleware the job should pass through
     */
    public function middleware(): array
    {
        return [
            // Prevent overlapping jobs for the same chunk
            new WithoutOverlapping($this->chunkId)
        ];
    }

    /**
     * Determine if we should override existing AI text
     * 
     * @return bool True if we should override existing AI text
     */
    protected function shouldOverrideExisting(): bool
    {
        // For now, don't override existing AI text
        // This could be made configurable in the future
        return false;
    }

    /**
     * Get the tags that should be assigned to the job
     */
    public function tags(): array
    {
        return ['ai-processing', 'chunk:' . $this->chunkId];
    }
}
