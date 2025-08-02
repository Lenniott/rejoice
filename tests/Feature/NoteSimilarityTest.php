<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Note;
use App\Models\Chunk;
use App\Models\VectorEmbedding;
use App\Services\VectorService;
use App\Services\QdrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class NoteSimilarityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected VectorService $vectorService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->vectorService = new VectorService();
    }

    public function test_note_to_note_similarity_workflow()
    {
        // Create source note with content
        $sourceNote = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Machine Learning Fundamentals'
        ]);

        Chunk::create([
            'note_id' => $sourceNote->id,
            'dictation_text' => 'Machine learning is a subset of artificial intelligence that focuses on algorithms.',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Create similar note
        $similarNote = Note::create([
            'user_id' => $this->user->id,
            'title' => 'AI and Deep Learning'
        ]);

        Chunk::create([
            'note_id' => $similarNote->id,
            'dictation_text' => 'Artificial intelligence and deep learning are transforming technology.',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Create unrelated note
        $unrelatedNote = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Cooking Recipes'
        ]);

        Chunk::create([
            'note_id' => $unrelatedNote->id,
            'dictation_text' => 'Here are some delicious pasta recipes for dinner.',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Mock QdrantService for vectorization
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn(array_fill(0, 768, 0.1));
        $mockQdrantService->shouldReceive('storeEmbedding')
            ->andReturn(true);

        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        // Vectorize all notes
        $sourceResult = $this->vectorService->vectorizeNote($sourceNote->id);
        $similarResult = $this->vectorService->vectorizeNote($similarNote->id);
        $unrelatedResult = $this->vectorService->vectorizeNote($unrelatedNote->id);

        $this->assertTrue($sourceResult['success']);
        $this->assertTrue($similarResult['success']);
        $this->assertTrue($unrelatedResult['success']);

        // Verify note-level embeddings were created
        $sourceEmbedding = VectorEmbedding::where('note_id', $sourceNote->id)
            ->whereNull('audio_id')
            ->where('chunk_ids', '[]')
            ->first();
        
        $this->assertNotNull($sourceEmbedding);
        $this->assertEquals('note-level', $sourceResult['type']);
    }

    public function test_note_similarity_search_with_mocked_results()
    {
        // Create source note
        $sourceNote = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Source Note'
        ]);

        // Create source note embedding
        $sourceEmbedding = VectorEmbedding::create([
            'note_id' => $sourceNote->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => 'Source note about machine learning concepts',
            'embedding_model' => 'test-model'
        ]);

        // Create similar notes for results
        $similarNote1 = Note::create([
            'user_id' => $this->user->id,
            'title' => 'AI Fundamentals'
        ]);

        $similarNote2 = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Deep Learning Guide'
        ]);

        // Mock the search to return similar results
        $vectorService = Mockery::mock(VectorService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $vectorService->shouldReceive('searchNoteLevelVectors')
            ->andReturn([
                [
                    'score' => 0.90,
                    'payload' => [
                        'note_id' => $similarNote1->id,
                        'source_text' => 'Artificial intelligence and machine learning fundamentals'
                    ]
                ],
                [
                    'score' => 0.85,
                    'payload' => [
                        'note_id' => $similarNote2->id,
                        'source_text' => 'Deep learning is a subset of machine learning'
                    ]
                ],
                [
                    'score' => 0.95, // Higher score but is source note (should be filtered)
                    'payload' => [
                        'note_id' => $sourceNote->id,
                        'source_text' => 'Source note about machine learning concepts'
                    ]
                ]
            ]);

        $result = $vectorService->findSimilarNotes($sourceNote->id, 5, 0.8);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['similar_notes']); // Source note filtered out
        
        // Verify results are sorted by similarity score (descending)
        $this->assertGreaterThanOrEqual(
            $result['similar_notes'][1]['similarity_score'],
            $result['similar_notes'][0]['similarity_score']
        );

        // Verify source note information is included
        $this->assertEquals($sourceNote->id, $result['source_note']['note_id']);
        $this->assertEquals($sourceNote->title, $result['source_note']['note_title']);
    }

    public function test_note_content_aggregation_preserves_order()
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note'
        ]);

        // Create chunks in non-sequential order
        Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'This is the third chunk.',
            'active_version' => 'dictation',
            'chunk_order' => 3
        ]);

        Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'This is the first chunk.',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'This is the second chunk.',
            'active_version' => 'dictation',
            'chunk_order' => 2
        ]);

        $result = $this->vectorService->aggregateNoteContent($note->id);

        $this->assertEquals(
            'This is the first chunk. This is the second chunk. This is the third chunk.',
            $result['text']
        );
        $this->assertCount(3, $result['chunk_ids']);
    }

    public function test_note_aggregation_respects_active_version()
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note'
        ]);

        // Chunk with edited version active
        Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'Original dictation text.',
            'ai_text' => 'AI enhanced text.',
            'edited_text' => 'Manually edited text.',
            'active_version' => 'edited',
            'chunk_order' => 1
        ]);

        // Chunk with AI version active
        Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'Another dictation.',
            'ai_text' => 'AI enhanced version.',
            'active_version' => 'ai',
            'chunk_order' => 2
        ]);

        // Chunk with dictation version active
        Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'Simple dictation.',
            'active_version' => 'dictation',
            'chunk_order' => 3
        ]);

        $result = $this->vectorService->aggregateNoteContent($note->id);

        $this->assertEquals(
            'Manually edited text. AI enhanced version. Simple dictation.',
            $result['text']
        );
    }

    public function test_note_vectorization_change_detection()
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note'
        ]);

        $originalText = 'This is the original note content.';
        
        // Create existing embedding
        VectorEmbedding::create([
            'note_id' => $note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => $originalText,
            'embedding_model' => 'test-model',
            'text_hash' => hash('sha256', $originalText)
        ]);

        // Create chunk with same content (no significant change)
        Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => $originalText,
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        $result = $this->vectorService->vectorizeNote($note->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertEquals('No significant change', $result['reason']);
    }

    public function test_note_vectorization_with_significant_change()
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note'
        ]);

        $originalText = 'This is the original note content.';
        $newText = 'This is completely different content with many changes and additions.';
        
        // Create existing embedding
        VectorEmbedding::create([
            'note_id' => $note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => $originalText,
            'embedding_model' => 'test-model',
            'text_hash' => hash('sha256', $originalText)
        ]);

        // Create chunk with significantly different content
        Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => $newText,
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Mock QdrantService for re-vectorization
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn(array_fill(0, 768, 0.1));
        $mockQdrantService->shouldReceive('storeEmbedding')
            ->andReturn(true);
        $mockQdrantService->shouldReceive('deleteEmbedding')
            ->andReturn(true);

        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        $result = $this->vectorService->vectorizeNote($note->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['skipped'] ?? false);
        $this->assertEquals('note-level', $result['type']);
    }

    public function test_dual_level_search_separates_chunk_and_note_results()
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note'
        ]);

        // Create audio file for chunk-level result
        $audioFile = \App\Models\AudioFile::create([
            'note_id' => $note->id,
            'path' => 'audio/test/test.webm',
            'mime_type' => 'audio/webm'
        ]);

        // Mock dual-level search results with proper VectorService instance
        $vectorService = new VectorService();
        $mockVectorService = Mockery::mock(VectorService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $mockVectorService->shouldReceive('searchVectorsWithEmbedding')
            ->andReturn([
                [
                    'score' => 0.85,
                    'payload' => [
                        'note_id' => $note->id,
                        'audio_id' => $audioFile->id,
                        'chunk_ids' => ['chunk-1'],
                        'source_text' => 'Chunk-level content'
                    ]
                ],
                [
                    'score' => 0.80,
                    'payload' => [
                        'note_id' => $note->id,
                        'audio_id' => null,
                        'chunk_ids' => [],
                        'source_text' => 'Note-level content'
                    ]
                ]
            ]);

        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn([0.1, 0.2, 0.3]);

        $reflection = new \ReflectionClass($mockVectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($mockVectorService, $mockQdrantService);

        // Set the constructor properties manually since we're using a mock
        $searchLimitProperty = $reflection->getProperty('searchLimit');
        $searchLimitProperty->setAccessible(true);
        $searchLimitProperty->setValue($mockVectorService, 10);

        $result = $mockVectorService->searchDualLevel('test query');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('chunk_results', $result);
        $this->assertArrayHasKey('note_results', $result);
        
        // Should have one result in each category
        $this->assertGreaterThan(0, count($result['chunk_results']));
        $this->assertGreaterThan(0, count($result['note_results']));
    }

    public function test_empty_notes_are_skipped_during_vectorization()
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Empty Note'
        ]);

        // Don't create any chunks
        $result = $this->vectorService->vectorizeNote($note->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertEquals('No content in note', $result['reason']);
    }

    public function test_note_similarity_threshold_filtering()
    {
        $sourceNote = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Source Note'
        ]);

        // Create source embedding
        VectorEmbedding::create([
            'note_id' => $sourceNote->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => 'Source content',
            'embedding_model' => 'test-model'
        ]);

        $lowScoreNote = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Low Score Note'
        ]);

        // Mock search with results below threshold
        $vectorService = Mockery::mock(VectorService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $vectorService->shouldReceive('searchNoteLevelVectors')
            ->andReturn([
                [
                    'score' => 0.5, // Below 0.7 threshold
                    'payload' => [
                        'note_id' => $lowScoreNote->id,
                        'source_text' => 'Low similarity content'
                    ]
                ]
            ]);

        $result = $vectorService->findSimilarNotes($sourceNote->id, 5, 0.7);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['similar_notes']); // Filtered out by threshold
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}