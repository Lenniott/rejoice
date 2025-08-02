<?php

/**
 * Custom Gemini Embedder - Fixed implementation for Google Gemini embedding API
 * 
 * Requirements:
 * - Use correct Gemini API v1beta endpoint and format
 * - Proper x-goog-api-key authentication method
 * - Handle Gemini's expected request/response structure
 * - Replace the buggy SDK implementation
 * 
 * Flow:
 * - Text input -> Format for Gemini API -> Send request with API key -> Extract embedding values
 */

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CustomGeminiEmbedder
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('larq.gemini_api_key');
        $this->model = config('larq.gemini_model', 'models/embedding-001');
    }

    /**
     * Generate embedding using Google Gemini API
     */
    public function embed(string $text): ?array
    {
        try {
            // Use the correct Gemini API format and endpoint
            $response = Http::acceptJson()
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                ])
                ->post("https://generativelanguage.googleapis.com/v1beta/{$this->model}:embedContent", [
                    'content' => [
                        'parts' => [
                            ['text' => $text]
                        ]
                    ]
                ]);

            if (!$response->successful()) {
                Log::error('Gemini embedding failed: ' . $response->body());
                return null;
            }

            $data = $response->json();
            
            // Extract the embedding values from the response
            return $data['embedding']['values'] ?? null;

        } catch (\Exception $e) {
            Log::error('Gemini embedding error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if the embedder is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Get the model name being used
     */
    public function getModel(): string
    {
        return $this->model;
    }
}