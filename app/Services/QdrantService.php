<?php

/**
 * Qdrant Vector Search Service - Handles vector operations for semantic search
 * 
 * Requirements:
 * - Create and manage Qdrant collections for voice notes
 * - Generate embeddings using AI models (Gemini or OpenAI)
 * - Store and retrieve vector embeddings
 * - Perform semantic similarity searches
 * - Handle vector metadata and payload management
 * 
 * Flow:
 * - Text prepared -> AI model generates embedding vector -> Store in Qdrant with metadata
 * - Search query -> Generate query embedding -> Find similar vectors -> Return relevant results
 */

namespace App\Services;

use App\Models\VectorEmbedding;
use Wontonee\LarQ\Qdrant\Collections\CreateCollection;
use Wontonee\LarQ\Qdrant\Collections\ListCollections;
use Wontonee\LarQ\Qdrant\Points\UpsertPoints;
use Wontonee\LarQ\Qdrant\Points\SearchPoints;
use Wontonee\LarQ\Qdrant\Points\DeletePoints;
use Wontonee\LarQ\Embedders\OpenAIEmbedder;
use App\Services\CustomGeminiEmbedder;
use Illuminate\Support\Facades\Log;
use Exception;

class QdrantService
{
    protected $collectionName = 'voice_notes_v1';

    public function __construct()
    {
        // Constructor no longer needed - using action classes directly
    }

    /**
     * Initialize the Qdrant collection for voice notes
     */
    public function initializeCollection(): bool
    {
        try {
            // Check if collection exists
            $listCollections = new ListCollections();
            $response = $listCollections->handle();
            $responseData = $response->json();
            
            if (isset($responseData['result']['collections'])) {
                foreach ($responseData['result']['collections'] as $collection) {
                    if ($collection['name'] === $this->collectionName) {
                        Log::info("Qdrant collection '{$this->collectionName}' already exists");
                        return true;
                    }
                }
            }

            // Create collection with configurable vector size
            $vectorSize = config('larq.vector_size', config('larq.gemini_api_key') ? 768 : 1536);
            
            $vectorParams = [
                'size' => $vectorSize,
                'distance' => 'Cosine' // Cosine similarity for semantic search
            ];
            
            $createCollection = new CreateCollection();
            $result = $createCollection->handle($this->collectionName, $vectorParams);

            if ($result->successful()) {
                Log::info("Created Qdrant collection '{$this->collectionName}' with vector size {$vectorSize}");
                return true;
            } else {
                Log::error("Failed to create collection: " . $result->body());
                return false;
            }

        } catch (Exception $e) {
            Log::error("Failed to initialize Qdrant collection: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate embedding for text using configured AI model
     */
    public function generateEmbedding(string $text): ?array
    {
        try {
            // Use Gemini if API key is configured, otherwise OpenAI
            if (config('larq.gemini_api_key')) {
                $geminiEmbedder = new CustomGeminiEmbedder();
                $embedding = $geminiEmbedder->embed($text);
            } elseif (config('larq.openai_api_key')) {
                $openaiEmbedder = new OpenAIEmbedder();
                $embedding = $openaiEmbedder->embed($text);
            } else {
                Log::error("No AI model API key configured for embeddings");
                return null;
            }

            return $embedding;

        } catch (Exception $e) {
            Log::error("Failed to generate embedding: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store vector embedding in Qdrant
     */
    public function storeEmbedding(VectorEmbedding $vectorEmbedding, array $embedding): bool
    {
        try {
            $payload = [
                'note_id' => $vectorEmbedding->note_id,
                'audio_id' => $vectorEmbedding->audio_id,
                'chunk_ids' => $vectorEmbedding->chunk_ids,
                'source_text' => $vectorEmbedding->source_text,
                'created_at' => $vectorEmbedding->created_at->toISOString(),
                'embedding_model' => $vectorEmbedding->embedding_model,
            ];

            $points = [
                [
                    'id' => $vectorEmbedding->qdrant_point_id,
                    'vector' => $embedding,
                    'payload' => $payload,
                ]
            ];

            $upsertPoints = new UpsertPoints();
            $result = $upsertPoints->handle($this->collectionName, $points);

            if ($result->successful()) {
                Log::info("Stored embedding in Qdrant for note {$vectorEmbedding->note_id}");
                return true;
            } else {
                Log::error("Failed to store embedding: " . $result->body());
                return false;
            }

        } catch (Exception $e) {
            Log::error("Failed to store embedding in Qdrant: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search for similar vectors
     */
    public function searchSimilar(string $queryText, int $limit = 10, float $scoreThreshold = 0.7): array
    {
        try {
            // Generate embedding for search query
            $queryEmbedding = $this->generateEmbedding($queryText);
            if (!$queryEmbedding) {
                return [];
            }

            // Search for similar vectors
            $searchParams = [
                'vector' => $queryEmbedding,
                'limit' => $limit,
                'score_threshold' => $scoreThreshold,
                'with_payload' => true
            ];

            $searchPoints = new SearchPoints();
            $response = $searchPoints->handle($this->collectionName, $searchParams);
            $responseData = $response->json();

            // Transform results to include metadata
            $searchResults = [];
            if (isset($responseData['result'])) {
                foreach ($responseData['result'] as $result) {
                    $searchResults[] = [
                        'score' => $result['score'],
                        'note_id' => $result['payload']['note_id'],
                        'audio_id' => $result['payload']['audio_id'] ?? null,
                        'chunk_ids' => $result['payload']['chunk_ids'] ?? [],
                        'source_text' => $result['payload']['source_text'],
                        'created_at' => $result['payload']['created_at'],
                    ];
                }
            }

            Log::info("Found " . count($searchResults) . " similar results for query");
            return $searchResults;

        } catch (Exception $e) {
            Log::error("Failed to search vectors in Qdrant: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search for similar vectors using a pre-generated embedding
     */
    public function searchWithEmbedding(array $queryEmbedding, int $limit = 10, float $scoreThreshold = 0.7): array
    {
        try {
            // Search for similar vectors using the provided embedding
            $searchParams = [
                'vector' => $queryEmbedding,
                'limit' => $limit,
                'score_threshold' => $scoreThreshold,
                'with_payload' => true
            ];

            $searchPoints = new SearchPoints();
            $response = $searchPoints->handle($this->collectionName, $searchParams);
            $responseData = $response->json();

            // Transform results to include metadata
            $searchResults = [];
            if (isset($responseData['result'])) {
                foreach ($responseData['result'] as $result) {
                    $searchResults[] = [
                        'id' => $result['id'] ?? null,
                        'score' => $result['score'],
                        'payload' => [
                            'note_id' => $result['payload']['note_id'] ?? null,
                            'audio_id' => $result['payload']['audio_id'] ?? null,
                            'chunk_ids' => $result['payload']['chunk_ids'] ?? [],
                            'source_text' => $result['payload']['source_text'] ?? null,
                            'created_at' => $result['payload']['created_at'] ?? null,
                        ]
                    ];
                }
            }

            Log::info("Found " . count($searchResults) . " similar results using provided embedding");
            return $searchResults;

        } catch (Exception $e) {
            Log::error("Failed to search vectors with embedding in Qdrant: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete vector embedding from Qdrant
     */
    public function deleteEmbedding(string $qdrantPointId): bool
    {
        try {
            $deletePoints = new DeletePoints();
            $result = $deletePoints->handle($this->collectionName, [$qdrantPointId]);
            
            if ($result->successful()) {
                Log::info("Deleted embedding {$qdrantPointId} from Qdrant");
                return true;
            } else {
                Log::error("Failed to delete embedding: " . $result->body());
                return false;
            }

        } catch (Exception $e) {
            Log::error("Failed to delete embedding from Qdrant: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get Qdrant cluster info for health checks
     */
    public function getClusterInfo(): ?array
    {
        try {
            $listCollections = new ListCollections();
            $response = $listCollections->handle();
            
            // Extract JSON data from HTTP response
            if ($response->successful()) {
                return $response->json();
            }
            
            return null;
        } catch (Exception $e) {
            Log::error("Failed to get Qdrant cluster info: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Health check for Qdrant connectivity
     */
    public function healthCheck(): bool
    {
        try {
            $info = $this->getClusterInfo();
            return $info !== null && isset($info['result']);
        } catch (Exception $e) {
            Log::error("Qdrant health check failed: " . $e->getMessage());
            return false;
        }
    }
}