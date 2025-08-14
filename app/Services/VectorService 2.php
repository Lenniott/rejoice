<?php

/**
 * VectorService - Enhanced with dual-level vectorization
 * 
 * Requirements:
 * - Chunk-level vectorization: Text segmentation for content search
 * - Note-level vectorization: Whole note vectors for note-to-note similarity
 * - Dual-level search: Separate results for chunks vs notes
 * - Change detection using Levenshtein similarity (>20% threshold)
 * 
 * Flow:
 * - Chunk-level: Text input -> Segment text -> Generate embeddings -> Store with chunk_ids
 * - Note-level: Note ID -> Aggregate chunks -> Single embedding -> Store with chunk_ids=[]
 * - Search: Query -> Generate embedding -> Return both chunk and note results separately
 */

namespace App\Services;

use App\Models\VectorEmbedding;
use App\Models\Note;
use App\Models\Chunk;
use App\Services\QdrantService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Wontonee\LarQ\Qdrant\Points\SearchPoints;
use Exception;

class VectorService
{
    protected QdrantService $qdrantService;
    protected int $maxWordsPerSegment;
    protected int $overlapWords;
    protected float $changeThreshold;
    protected int $searchLimit;

    public function __construct()
    {
        $this->qdrantService = new QdrantService();
        $this->maxWordsPerSegment = config('larq.vector_segment_max_words', 300);
        $this->overlapWords = config('larq.vector_segment_overlap_words', 50);
        $this->changeThreshold = config('larq.vector_similarity_threshold', 0.2);
        $this->searchLimit = config('larq.vector_search_limit', 10);
    }

    /**
     * Vectorize content for a note (chunk-level vectorization)
     *
     * @param string $noteId UUID of the parent note
     * @param string $text The text content to vectorize
     * @param array $chunkIds Array of chunk UUIDs included in this text
     * @return array Results with success status and vector IDs created
     */
    public function vectorizeContent(string $noteId, string $text, array $chunkIds = []): array
    {
        if (empty(trim($text))) {
            Log::warning('VectorService: Empty text provided for vectorization', [
                'note_id' => $noteId
            ]);
            return ['success' => false, 'error' => 'Empty text content'];
        }

        try {
            // Check if we should re-embed (if existing vectors found)
            $existingEmbedding = $this->getExistingEmbedding($noteId, $chunkIds);
            if ($existingEmbedding && !$this->shouldReembed($existingEmbedding->source_text, $text)) {
                Log::info('VectorService: Text has not changed significantly, skipping re-embedding', [
                    'note_id' => $noteId,
                    'existing_embedding_id' => $existingEmbedding->id
                ]);
                return ['success' => true, 'skipped' => true, 'reason' => 'No significant change'];
            }

            // Segment the text
            $segments = $this->segmentText($text);
            Log::info('VectorService: Text segmented', [
                'note_id' => $noteId,
                'segment_count' => count($segments),
                'text_length' => strlen($text)
            ]);

            // Delete existing embeddings for this note/chunk combination
            if ($existingEmbedding) {
                $this->deleteExistingEmbeddings($noteId, $chunkIds);
            }

            // Generate embeddings and store vectors
            $vectorIds = [];
            foreach ($segments as $index => $segment) {
                $vectorId = $this->createVectorForSegment($noteId, $segment, $chunkIds, $index, $text);
                if ($vectorId) {
                    $vectorIds[] = $vectorId;
                }
            }

            Log::info('VectorService: Content vectorized successfully', [
                'note_id' => $noteId,
                'vector_count' => count($vectorIds),
                'segment_count' => count($segments)
            ]);

            return [
                'success' => true,
                'vector_ids' => $vectorIds,
                'segment_count' => count($segments)
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error vectorizing content', [
                'note_id' => $noteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Vectorization failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Vectorize a note by aggregating all its chunks
     *
     * @param string $noteId UUID of the note to vectorize
     * @return array Results with success status and vector ID created
     */
    public function vectorizeNote(string $noteId): array
    {
        try {
            $note = Note::find($noteId);
            if (!$note) {
                return ['success' => false, 'error' => 'Note not found'];
            }

            // Get all chunks for this note
            $chunks = Chunk::where('note_id', $noteId)
                ->orderBy('chunk_order')
                ->get();

            if ($chunks->isEmpty()) {
                Log::info('VectorService: No chunks found for note', ['note_id' => $noteId]);
                return ['success' => false, 'error' => 'No chunks found for note'];
            }

            // Aggregate chunk content
            $aggregatedContent = $this->aggregateNoteContent($noteId);
            if (!$aggregatedContent['success']) {
                return $aggregatedContent;
            }

            $text = $aggregatedContent['text'];
            $chunkIds = $aggregatedContent['chunk_ids'];

            // Check if note-level vector already exists
            $existingEmbedding = $this->getNoteLevelEmbedding($noteId);
            if ($existingEmbedding && !$this->shouldReembed($existingEmbedding->source_text, $text)) {
                Log::info('VectorService: Note content has not changed significantly, skipping re-embedding', [
                    'note_id' => $noteId,
                    'existing_embedding_id' => $existingEmbedding->id
                ]);
                return ['success' => true, 'skipped' => true, 'reason' => 'No significant change'];
            }

            // Delete existing note-level embeddings
            if ($existingEmbedding) {
                $this->deleteNoteLevelEmbeddings($noteId);
            }

            // Create note-level vector
            $vectorId = $this->createNoteLevelVector($noteId, $text, $chunkIds);
            if (!$vectorId) {
                return ['success' => false, 'error' => 'Failed to create note-level vector'];
            }

            Log::info('VectorService: Note vectorized successfully', [
                'note_id' => $noteId,
                'vector_id' => $vectorId,
                'chunk_count' => count($chunks)
            ]);

            return [
                'success' => true,
                'vector_id' => $vectorId,
                'chunk_count' => count($chunks)
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error vectorizing note', [
                'note_id' => $noteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Note vectorization failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Aggregate content from all chunks in a note
     *
     * @param string $noteId UUID of the note
     * @return array Aggregated content and chunk IDs
     */
    public function aggregateNoteContent(string $noteId): array
    {
        try {
            $chunks = Chunk::where('note_id', $noteId)
                ->orderBy('chunk_order')
                ->get();

            if ($chunks->isEmpty()) {
                return ['success' => false, 'error' => 'No chunks found'];
            }

            $text = '';
            $chunkIds = [];

            foreach ($chunks as $chunk) {
                $activeText = $chunk->ai_text ?: $chunk->edited_text;
                if (!empty($activeText)) {
                    $text .= $activeText . "\n";
                    $chunkIds[] = $chunk->id;
                }
            }

            if (empty(trim($text))) {
                return ['success' => false, 'error' => 'No text content found in chunks'];
            }

            return [
                'success' => true,
                'text' => trim($text),
                'chunk_ids' => $chunkIds
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error aggregating note content', [
                'note_id' => $noteId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Content aggregation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Find notes similar to a given note
     *
     * @param string $noteId UUID of the note to find similarities for
     * @param int $limit Maximum number of similar notes to return
     * @param float $threshold Similarity threshold (0.0 to 1.0)
     * @return array Similar notes with scores
     */
    public function findSimilarNotes(string $noteId, int $limit = 5, float $threshold = 0.7): array
    {
        try {
            $note = Note::find($noteId);
            if (!$note) {
                return ['success' => false, 'error' => 'Note not found'];
            }

            // Get note-level embedding
            $embedding = $this->getNoteLevelEmbedding($noteId);
            if (!$embedding) {
                Log::warning('VectorService: No note-level embedding found for similarity search', [
                    'note_id' => $noteId
                ]);
                return ['success' => false, 'error' => 'No note-level embedding found'];
            }

            // Search for similar notes
            $searchResults = $this->searchNoteLevelVectors($embedding->qdrant_point_id, $limit + 1);
            if (!$searchResults['success']) {
                return $searchResults;
            }

            $results = $searchResults['results'];
            $similarNotes = [];

            foreach ($results as $result) {
                if ($result['qdrant_point_id'] === $embedding->qdrant_point_id) {
                    continue; // Skip self
                }

                if ($result['score'] >= $threshold) {
                    $similarNotes[] = [
                        'note_id' => $result['note_id'],
                        'score' => $result['score'],
                        'title' => $result['title'] ?? 'Untitled',
                        'text_preview' => $result['text_preview'] ?? ''
                    ];
                }

                if (count($similarNotes) >= $limit) {
                    break;
                }
            }

            Log::info('VectorService: Similar notes found', [
                'note_id' => $noteId,
                'similar_count' => count($similarNotes),
                'threshold' => $threshold
            ]);

            return [
                'success' => true,
                'similar_notes' => $similarNotes,
                'total_found' => count($similarNotes)
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error finding similar notes', [
                'note_id' => $noteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Similarity search failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Search for content using dual-level approach (chunks + notes)
     *
     * @param string $query Search query text
     * @param int|null $limit Maximum results per level
     * @param float $threshold Similarity threshold
     * @return array Search results with both chunk and note level results
     */
    public function searchDualLevel(string $query, int $limit = null, float $threshold = 0.7): array
    {
        $limit = $limit ?: $this->searchLimit;

        try {
            // Generate query embedding
            $queryEmbedding = $this->qdrantService->generateEmbedding($query);
            if (!$queryEmbedding) {
                return ['success' => false, 'error' => 'Failed to generate query embedding'];
            }

            // Search both levels
            $chunkResults = $this->searchVectorsWithEmbedding($queryEmbedding, $limit);
            $noteResults = $this->searchNoteLevelVectors($query, $limit);

            // Format results
            $formattedChunks = $this->formatSearchResults($chunkResults, $threshold, $limit);
            $formattedNotes = $this->formatSearchResults($noteResults, $threshold, $limit);

            Log::info('VectorService: Dual-level search completed', [
                'query' => $query,
                'chunk_results' => count($formattedChunks),
                'note_results' => count($formattedNotes),
                'threshold' => $threshold
            ]);

            return [
                'success' => true,
                'chunk_results' => $formattedChunks,
                'note_results' => $formattedNotes,
                'total_chunks' => count($formattedChunks),
                'total_notes' => count($formattedNotes)
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error in dual-level search', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Search failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Simple search for content (chunk-level only)
     *
     * @param string $query Search query text
     * @param int|null $limit Maximum results to return
     * @param float $threshold Similarity threshold
     * @return array Search results
     */
    public function searchSimilar(string $query, int $limit = null, float $threshold = 0.7): array
    {
        $limit = $limit ?: $this->searchLimit;

        try {
            // Generate query embedding
            $queryEmbedding = $this->qdrantService->generateEmbedding($query);
            if (!$queryEmbedding) {
                return ['success' => false, 'error' => 'Failed to generate query embedding'];
            }

            // Search vectors
            $searchResults = $this->searchVectorsWithEmbedding($queryEmbedding, $limit);

            // Format results
            $formattedResults = $this->formatSearchResults($searchResults, $threshold, $limit);

            Log::info('VectorService: Simple search completed', [
                'query' => $query,
                'results' => count($formattedResults),
                'threshold' => $threshold
            ]);

            return [
                'success' => true,
                'results' => $formattedResults,
                'total_found' => count($formattedResults)
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error in simple search', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Search failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Segment text into smaller chunks for vectorization
     *
     * @param string $text Text to segment
     * @return array Array of text segments
     */
    public function segmentText(string $text): array
    {
        $words = preg_split('/\s+/', trim($text));
        $segments = [];
        $currentSegment = '';
        $wordCount = 0;

        foreach ($words as $word) {
            if ($wordCount >= $this->maxWordsPerSegment) {
                if (!empty($currentSegment)) {
                    $segments[] = trim($currentSegment);
                }
                $currentSegment = $word;
                $wordCount = 1;
            } else {
                $currentSegment .= ($currentSegment ? ' ' : '') . $word;
                $wordCount++;
            }
        }

        if (!empty($currentSegment)) {
            $segments[] = trim($currentSegment);
        }

        return $segments;
    }

    /**
     * Check if text has changed significantly (>20% difference)
     *
     * @param string $oldText Previous text
     * @param string $newText New text
     * @return bool True if change is significant
     */
    public function shouldReembed(string $oldText, string $newText): bool
    {
        if (empty($oldText) || empty($newText)) {
            return true;
        }

        $oldWords = str_word_count($oldText);
        $newWords = str_word_count($newText);

        if ($oldWords === 0) {
            return $newWords > 0;
        }

        $changePercentage = abs($newWords - $oldWords) / $oldWords;
        return $changePercentage > $this->changeThreshold;
    }

    /**
     * Delete all vectors for a specific note
     *
     * @param string $noteId UUID of the note
     * @return int Number of vectors deleted
     */
    public function deleteVectorsByNote(string $noteId): int
    {
        try {
            $deletedCount = VectorEmbedding::where('note_id', $noteId)->delete();
            
            Log::info('VectorService: Vectors deleted for note', [
                'note_id' => $noteId,
                'deleted_count' => $deletedCount
            ]);

            return $deletedCount;

        } catch (Exception $e) {
            Log::error('VectorService: Error deleting vectors for note', [
                'note_id' => $noteId,
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Get vector statistics
     *
     * @return array Statistics about vectors in the system
     */
    public function getVectorStats(): array
    {
        try {
            $totalVectors = VectorEmbedding::count();
            $noteLevelVectors = VectorEmbedding::whereNull('chunk_ids')
                ->orWhere('chunk_ids', '[]')
                ->count();
            $chunkLevelVectors = $totalVectors - $noteLevelVectors;

            return [
                'success' => true,
                'total_vectors' => $totalVectors,
                'note_level_vectors' => $noteLevelVectors,
                'chunk_level_vectors' => $chunkLevelVectors
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error getting vector stats', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get existing embedding for a note/chunk combination
     *
     * @param string $noteId UUID of the note
     * @param array $chunkIds Array of chunk IDs
     * @return VectorEmbedding|null
     */
    protected function getExistingEmbedding(string $noteId, array $chunkIds): ?VectorEmbedding
    {
        $query = VectorEmbedding::where('note_id', $noteId);
        
        if (!empty($chunkIds)) {
            $query->whereJsonContains('chunk_ids', $chunkIds);
        } else {
            $query->whereNull('chunk_ids')
                  ->orWhere('chunk_ids', '[]');
        }

        return $query->first();
    }

    /**
     * Get note-level embedding for a note
     *
     * @param string $noteId UUID of the note
     * @return VectorEmbedding|null
     */
    protected function getNoteLevelEmbedding(string $noteId): ?VectorEmbedding
    {
        return VectorEmbedding::where('note_id', $noteId)
            ->whereNull('chunk_ids')
            ->orWhere('chunk_ids', '[]')
            ->first();
    }

    /**
     * Delete existing embeddings for a note/chunk combination
     *
     * @param string $noteId UUID of the note
     * @param array $chunkIds Array of chunk IDs
     */
    protected function deleteExistingEmbeddings(string $noteId, array $chunkIds): void
    {
        $query = VectorEmbedding::where('note_id', $noteId);
        
        if (!empty($chunkIds)) {
            $query->whereJsonContains('chunk_ids', $chunkIds);
        } else {
            $query->whereNull('chunk_ids')
                  ->orWhere('chunk_ids', '[]');
        }

        $query->delete();
    }

    /**
     * Delete note-level embeddings for a note
     *
     * @param string $noteId UUID of the note
     */
    protected function deleteNoteLevelEmbeddings(string $noteId): void
    {
        VectorEmbedding::where('note_id', $noteId)
            ->whereNull('chunk_ids')
            ->orWhere('chunk_ids', '[]')
            ->delete();
    }

    /**
     * Create vector for a text segment
     *
     * @param string $noteId UUID of the note
     * @param string $segment Text segment
     * @param array $chunkIds Array of chunk IDs
     * @param int $segmentIndex Index of the segment
     * @param string $fullText Full text for context
     * @return string|null Vector ID or null on failure
     */
    protected function createVectorForSegment(string $noteId, string $segment, array $chunkIds, int $segmentIndex, string $fullText): ?string
    {
        try {
            // Generate embedding
            $embedding = $this->qdrantService->generateEmbedding($segment);
            if (!$embedding) {
                Log::warning('VectorService: Failed to generate embedding for segment', [
                    'note_id' => $noteId,
                    'segment_index' => $segmentIndex
                ]);
                return null;
            }

            // Store in Qdrant
            $qdrantPointId = $this->qdrantService->storeVector($embedding, [
                'note_id' => $noteId,
                'chunk_ids' => $chunkIds,
                'segment_index' => $segmentIndex,
                'source_text' => $segment,
                'full_text' => $fullText
            ]);

            if (!$qdrantPointId) {
                Log::warning('VectorService: Failed to store vector in Qdrant', [
                    'note_id' => $noteId,
                    'segment_index' => $segmentIndex
                ]);
                return null;
            }

            // Create database record
            $vectorEmbedding = VectorEmbedding::create([
                'note_id' => $noteId,
                'chunk_ids' => $chunkIds,
                'qdrant_point_id' => $qdrantPointId,
                'source_text' => $segment,
                'embedding_model' => config('larq.embedding_model', 'gemini-2.5-flash'),
                'text_hash' => hash('sha256', $segment)
            ]);

            return $vectorEmbedding->id;

        } catch (Exception $e) {
            Log::error('VectorService: Error creating vector for segment', [
                'note_id' => $noteId,
                'segment_index' => $segmentIndex,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Create note-level vector
     *
     * @param string $noteId UUID of the note
     * @param string $text Aggregated text
     * @param array $sourceChunkIds Array of source chunk IDs
     * @return string|null Vector ID or null on failure
     */
    protected function createNoteLevelVector(string $noteId, string $text, array $sourceChunkIds): ?string
    {
        try {
            // Generate embedding
            $embedding = $this->qdrantService->generateEmbedding($text);
            if (!$embedding) {
                Log::warning('VectorService: Failed to generate note-level embedding', [
                    'note_id' => $noteId
                ]);
                return null;
            }

            // Store in Qdrant
            $qdrantPointId = $this->qdrantService->storeVector($embedding, [
                'note_id' => $noteId,
                'chunk_ids' => [],
                'source_text' => $text,
                'is_note_level' => true
            ]);

            if (!$qdrantPointId) {
                Log::warning('VectorService: Failed to store note-level vector in Qdrant', [
                    'note_id' => $noteId
                ]);
                return null;
            }

            // Create database record
            $vectorEmbedding = VectorEmbedding::create([
                'note_id' => $noteId,
                'chunk_ids' => [],
                'qdrant_point_id' => $qdrantPointId,
                'source_text' => $text,
                'embedding_model' => config('larq.embedding_model', 'gemini-2.5-flash'),
                'text_hash' => hash('sha256', $text)
            ]);

            return $vectorEmbedding->id;

        } catch (Exception $e) {
            Log::error('VectorService: Error creating note-level vector', [
                'note_id' => $noteId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Search note-level vectors
     *
     * @param string $query Search query
     * @param int $limit Maximum results
     * @return array Search results
     */
    protected function searchNoteLevelVectors(string $query, int $limit): array
    {
        try {
            $searchPoints = new SearchPoints();
            $searchPoints->setLimit($limit);
            $searchPoints->setScoreThreshold(0.0);

            $results = $this->qdrantService->searchVectors($query, $searchPoints, [
                'is_note_level' => true
            ]);

            return [
                'success' => true,
                'results' => $results
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error searching note-level vectors', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Search failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Format search results
     *
     * @param array $rawResults Raw search results
     * @param float $threshold Similarity threshold
     * @param int $limit Maximum results
     * @return array Formatted results
     */
    protected function formatSearchResults(array $rawResults, float $threshold, int $limit): array
    {
        if (!isset($rawResults['results'])) {
            return [];
        }

        $formatted = [];
        $count = 0;

        foreach ($rawResults['results'] as $result) {
            if ($count >= $limit) {
                break;
            }

            if ($result['score'] >= $threshold) {
                $formatted[] = [
                    'id' => $result['id'] ?? null,
                    'note_id' => $result['note_id'] ?? null,
                    'score' => $result['score'],
                    'title' => $result['title'] ?? 'Untitled',
                    'text_preview' => $result['text_preview'] ?? '',
                    'qdrant_point_id' => $result['qdrant_point_id'] ?? null
                ];
                $count++;
            }
        }

        return $formatted;
    }

    /**
     * Search vectors using an embedding
     *
     * @param array $queryEmbedding Query embedding vector
     * @param int $limit Maximum results
     * @return array Search results
     */
    protected function searchVectorsWithEmbedding(array $queryEmbedding, int $limit): array
    {
        try {
            $searchPoints = new SearchPoints();
            $searchPoints->setLimit($limit);
            $searchPoints->setScoreThreshold(0.0);

            $results = $this->qdrantService->searchVectors($queryEmbedding, $searchPoints);

            return [
                'success' => true,
                'results' => $results
            ];

        } catch (Exception $e) {
            Log::error('VectorService: Error searching vectors with embedding', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Search failed: ' . $e->getMessage()
            ];
        }
    }
}