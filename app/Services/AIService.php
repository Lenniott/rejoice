<?php

/**
 * AIService - Handles Google Gemini 2.5 Flash integration for text enhancement
 * 
 * Requirements:
 * - Enhance dictation text using Gemini 2.5 Flash API
 * - Process chunks with AI enhancement and update database
 * - Handle background job processing for AI operations
 * - Graceful error handling and fallback mechanisms
 * - Environment configuration validation
 * 
 * Flow:
 * - Raw dictation text -> Send to Gemini API for enhancement -> Store enhanced text -> Update chunk active_version
 * - Background job processing for efficiency and scalability
 */

namespace App\Services;

use App\Models\Chunk;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class AIService
{
    protected ?string $apiKey;
    protected string $model;
    protected string $baseUrl;
    protected int $timeout;
    protected int $maxTokens;
    protected float $temperature;

    public function __construct()
    {
        $this->apiKey = config('larq.gemini_api_key');
        $this->model = config('larq.gemini_model', 'gemini-2.0-flash'); // Using 2.0 Flash for text generation
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
        $this->timeout = config('larq.ai_timeout', 30); // 30 second timeout
        $this->maxTokens = config('larq.ai_max_tokens', 1000);
        $this->temperature = config('larq.ai_temperature', 0.3); // Lower for more consistent output
    }

    /**
     * Enhance dictation text using Gemini 2.5 Flash
     *
     * @param string $text The raw dictation text to enhance
     * @param array $context Additional context (note title, previous chunks, etc.)
     * @return string|null Enhanced text or null if processing failed
     */
    public function enhanceText(string $text, array $context = []): ?string
    {
        if (!$this->isConfigured()) {
            Log::warning('AIService: Gemini API key not configured');
            return null;
        }

        if (empty(trim($text))) {
            Log::warning('AIService: Empty text provided for enhancement');
            return null;
        }

        try {
            $prompt = $this->buildEnhancementPrompt($text, $context);
            
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                ])
                ->post("{$this->baseUrl}/{$this->model}:generateContent", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => $this->temperature,
                        'maxOutputTokens' => $this->maxTokens,
                        'candidateCount' => 1,
                    ]
                ]);

            if (!$response->successful()) {
                Log::error('AIService: Gemini API request failed', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                return null;
            }

            $data = $response->json();
            
            // Extract the enhanced text from the response
            $enhancedText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            
            if (empty($enhancedText)) {
                Log::error('AIService: No text content in Gemini response', ['response' => $data]);
                return null;
            }

            Log::info('AIService: Text enhanced successfully', [
                'original_length' => strlen($text),
                'enhanced_length' => strlen($enhancedText)
            ]);

            return trim($enhancedText);

        } catch (Exception $e) {
            Log::error('AIService: Text enhancement failed', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text)
            ]);
            return null;
        }
    }

    /**
     * Process a specific chunk with AI enhancement
     *
     * @param string $chunkId UUID of the chunk to process
     * @return bool True if processing was successful
     */
    public function processChunk(string $chunkId): bool
    {
        try {
            $chunk = Chunk::findOrFail($chunkId);
            
            // Get the source text to enhance (prefer edited over dictation)
            $sourceText = $chunk->edited_text ?: $chunk->dictation_text;
            
            if (empty($sourceText)) {
                Log::warning('AIService: No source text available for chunk', ['chunk_id' => $chunkId]);
                return false;
            }

            // Build context from the note
            $context = [
                'note_title' => $chunk->note->title ?? 'Untitled Note',
                'note_id' => $chunk->note_id,
                'chunk_order' => $chunk->chunk_order,
                'audio_linked' => !empty($chunk->audio_id)
            ];

            // Enhance the text
            $enhancedText = $this->enhanceText($sourceText, $context);
            
            if ($enhancedText === null) {
                Log::error('AIService: Failed to enhance text for chunk', ['chunk_id' => $chunkId]);
                return false;
            }

            // Update the chunk with enhanced text
            $chunk->update([
                'ai_text' => $enhancedText,
                'active_version' => 'ai'
            ]);

            Log::info('AIService: Chunk processed successfully', [
                'chunk_id' => $chunkId,
                'note_id' => $chunk->note_id
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('AIService: Chunk processing failed', [
                'chunk_id' => $chunkId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Process multiple chunks efficiently
     *
     * @param array $chunkIds Array of chunk UUIDs to process
     * @return array Results with success/failure for each chunk
     */
    public function batchProcessChunks(array $chunkIds): array
    {
        $results = [];
        
        foreach ($chunkIds as $chunkId) {
            $results[$chunkId] = $this->processChunk($chunkId);
        }
        
        $successCount = count(array_filter($results));
        $totalCount = count($results);
        
        Log::info('AIService: Batch processing completed', [
            'total_chunks' => $totalCount,
            'successful' => $successCount,
            'failed' => $totalCount - $successCount
        ]);
        
        return $results;
    }

    /**
     * Check if the AI service is properly configured
     *
     * @return bool True if API key is available
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Validate configuration and test API connectivity
     *
     * @return array Status information about the service
     */
    public function validateConfig(): array
    {
        $status = [
            'configured' => $this->isConfigured(),
            'api_accessible' => false,
            'model' => $this->model,
            'last_error' => null
        ];

        if (!$status['configured']) {
            $status['last_error'] = 'GEMINI_API_KEY not configured';
            return $status;
        }

        // Test API connectivity with a simple request
        try {
            $testResponse = Http::timeout(10)
                ->acceptJson()
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                ])
                ->post("{$this->baseUrl}/{$this->model}:generateContent", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => 'Test']
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 10,
                        'candidateCount' => 1,
                    ]
                ]);

            $status['api_accessible'] = $testResponse->successful();
            
            if (!$status['api_accessible']) {
                $status['last_error'] = "API test failed: {$testResponse->status()}";
            }

        } catch (Exception $e) {
            $status['last_error'] = "API test error: {$e->getMessage()}";
        }

        return $status;
    }

    /**
     * Get current model information and configuration
     *
     * @return array Model configuration details
     */
    public function getModelInfo(): array
    {
        return [
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'timeout' => $this->timeout,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'configured' => $this->isConfigured()
        ];
    }

    /**
     * Build enhancement prompt for the AI model
     *
     * @param string $text The text to enhance
     * @param array $context Additional context information
     * @return string The complete prompt for the AI
     */
    protected function buildEnhancementPrompt(string $text, array $context): string
    {
        $noteTitle = $context['note_title'] ?? 'Note';
        $audioLinked = $context['audio_linked'] ?? false;
        
        $prompt = "You are an AI assistant helping to improve voice-to-text transcriptions. ";
        $prompt .= "Please enhance the following dictation text by:\n\n";
        $prompt .= "1. Fixing grammar and spelling errors\n";
        $prompt .= "2. Improving sentence structure and clarity\n";
        $prompt .= "3. Maintaining the original meaning and intent\n";
        $prompt .= "4. Preserving technical terms and proper nouns\n";
        $prompt .= "5. Making the text more readable while keeping the natural tone\n\n";
        
        if ($audioLinked) {
            $prompt .= "This text was transcribed from an audio recording, so please account for typical speech patterns and potential transcription errors.\n\n";
        }
        
        $prompt .= "Context: This is from a note titled '{$noteTitle}'\n\n";
        $prompt .= "Original text to enhance:\n{$text}\n\n";
        $prompt .= "Please provide only the enhanced text without any explanations or additional commentary.";
        
        return $prompt;
    }

    /**
     * Get processing statistics for monitoring
     *
     * @return array Processing statistics
     */
    public function getProcessingStats(): array
    {
        // Get counts of chunks by processing status
        $totalChunks = Chunk::count();
        $aiProcessedChunks = Chunk::whereNotNull('ai_text')->count();
        $aiActiveChunks = Chunk::where('active_version', 'ai')->count();
        
        return [
            'total_chunks' => $totalChunks,
            'ai_processed_chunks' => $aiProcessedChunks,
            'ai_active_chunks' => $aiActiveChunks,
            'processing_rate' => $totalChunks > 0 ? round(($aiProcessedChunks / $totalChunks) * 100, 2) : 0,
            'ai_adoption_rate' => $totalChunks > 0 ? round(($aiActiveChunks / $totalChunks) * 100, 2) : 0
        ];
    }
}