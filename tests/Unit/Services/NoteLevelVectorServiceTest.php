<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\VectorService;
use App\Services\QdrantService;
use App\Models\VectorEmbedding;
use App\Models\Note;
use App\Models\AudioFile;
use App\Models\Chunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class NoteLevelVectorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected VectorService $vectorService;
    protected User $user;
    protected Note $note;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->vectorService = new VectorService();
        
        // Create test user and note
        $this->user = User::factory()->create();
        $this->note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note for Note-Level Vectorization'
        ]);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_aggregate_note_content_with_multiple_chunks()
    {
        // Create chunks with different active versions
        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'First chunk dictation text.',
            'ai_text' => 'First chunk AI enhanced text.',
            'active_version' => 'ai',
            'chunk_order' => 1
        ]);

        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Second chunk dictation.',
            'edited_text' => 'Second chunk manually edited text.',
            'active_version' => 'edited',
            'chunk_order' => 2
        ]);

        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Third chunk only dictation.',
            'active_version' => 'dictation',
            'chunk_order' => 3
        ]);

        $result = $this->vectorService->aggregateNoteContent($this->note->id);

        $this->assertEquals(
            'First chunk AI enhanced text. Second chunk manually edited text. Third chunk only dictation.',
            $result['text']
        );
        $this->assertCount(3, $result['chunk_ids']);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_aggregate_note_content_with_empty_chunks()
    {
        // Create chunk with empty text
        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => '',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Create chunk with valid text
        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Valid chunk text.',
            'active_version' => 'dictation',
            'chunk_order' => 2
        ]);

        $result = $this->vectorService->aggregateNoteContent($this->note->id);

        $this->assertEquals('Valid chunk text.', $result['text']);
        $this->assertCount(1, $result['chunk_ids']);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_aggregate_note_content_with_no_chunks()
    {
        $result = $this->vectorService->aggregateNoteContent($this->note->id);

        $this->assertEquals('', $result['text']);
        $this->assertCount(0, $result['chunk_ids']);
    }

    public function test_vectorize_note_success_flow()
    {
        // Create test chunks
        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'This is note content for vectorization.',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->once()
            ->andReturn(array_fill(0, 768, 0.1)); // Mock 768-dim embedding
        $mockQdrantService->shouldReceive('storeEmbedding')
            ->once()
            ->andReturn(true);

        // Replace service instance
        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        $result = $this->vectorService->vectorizeNote($this->note->id);

        $this->assertTrue($result['success']);
        $this->assertEquals('note-level', $result['type']);
        $this->assertNotNull($result['vector_id']);
        $this->assertEquals(1, $result['chunks_included']);

        // Verify database record created
        $vectorEmbedding = VectorEmbedding::where('note_id', $this->note->id)
            ->whereNull('audio_id')
            ->where('chunk_ids', '[]')
            ->first();
        
        $this->assertNotNull($vectorEmbedding);
        $this->assertEquals('This is note content for vectorization.', $vectorEmbedding->source_text);
    }

    public function test_vectorize_note_with_empty_content()
    {
        $result = $this->vectorService->vectorizeNote($this->note->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertEquals('No content in note', $result['reason']);
    }

    public function test_vectorize_note_with_nonexistent_note()
    {
        $result = $this->vectorService->vectorizeNote('fake-note-id');

        $this->assertFalse($result['success']);
        $this->assertEquals('Note not found', $result['error']);
    }

    public function test_vectorize_note_skips_when_no_significant_change()
    {
        $text = 'Existing note content for testing.';
        
        // Create existing vector embedding
        VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => $text,
            'embedding_model' => 'test-model',
            'text_hash' => hash('sha256', $text)
        ]);

        // Create chunk with same content
        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => $text,
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        $result = $this->vectorService->vectorizeNote($this->note->id);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertEquals('No significant change', $result['reason']);
    }

    public function test_find_similar_notes_success()
    {
        // Create source note embedding
        $sourceEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => 'Source note about machine learning',
            'embedding_model' => 'test-model'
        ]);

        // Create similar notes
        $similarNote1 = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Similar Note 1'
        ]);

        $similarNote2 = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Similar Note 2'
        ]);

        // Mock QdrantService and VectorService search
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn([0.1, 0.2, 0.3]);

        $vectorService = Mockery::mock(VectorService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $vectorService->shouldReceive('searchNoteLevelVectors')
            ->andReturn([
                [
                    'score' => 0.85,
                    'payload' => [
                        'note_id' => $similarNote1->id,
                        'source_text' => 'AI and machine learning concepts'
                    ]
                ],
                [
                    'score' => 0.75,
                    'payload' => [
                        'note_id' => $similarNote2->id,
                        'source_text' => 'Deep learning fundamentals'
                    ]
                ]
            ]);

        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);

        $result = $vectorService->findSimilarNotes($this->note->id, 5, 0.7);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['similar_notes']);
        $this->assertEquals($this->note->id, $result['source_note']['note_id']);
        
        // Verify similar notes are ordered by score
        $this->assertGreaterThanOrEqual(
            $result['similar_notes'][1]['similarity_score'],
            $result['similar_notes'][0]['similarity_score']
        );
    }

    public function test_find_similar_notes_no_embedding()
    {
        $result = $this->vectorService->findSimilarNotes($this->note->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no vector embedding', $result['error']);
    }

    public function test_find_similar_notes_excludes_source_note()
    {
        // Create source note embedding
        VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => 'Source note content',
            'embedding_model' => 'test-model'
        ]);

        // Mock to return source note in results (should be filtered out)
        $vectorService = Mockery::mock(VectorService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $vectorService->shouldReceive('searchNoteLevelVectors')
            ->andReturn([
                [
                    'score' => 1.0,
                    'payload' => [
                        'note_id' => $this->note->id, // Same as source
                        'source_text' => 'Source note content'
                    ]
                ]
            ]);

        $mockQdrantService = Mockery::mock(QdrantService::class);
        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);

        $result = $vectorService->findSimilarNotes($this->note->id);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['similar_notes']); // Source note filtered out
    }

    public function test_search_dual_level_separates_results()
    {
        // Create audio file for chunk-level embedding
        $audioFile = \App\Models\AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/test.webm',
            'mime_type' => 'audio/webm'
        ]);

        // Create chunk-level embedding
        $chunkEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'chunk_ids' => ['chunk-1'],
            'source_text' => 'Chunk content about AI',
            'embedding_model' => 'test-model'
        ]);

        // Create note-level embedding
        $noteEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => 'Note content about AI',
            'embedding_model' => 'test-model'
        ]);

        // Mock QdrantService and search
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn([0.1, 0.2, 0.3]);

        $vectorService = Mockery::mock(VectorService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $vectorService->shouldReceive('searchVectorsWithEmbedding')
            ->andReturn([
                [
                    'score' => 0.85,
                    'payload' => [
                        'note_id' => $this->note->id,
                        'audio_id' => $audioFile->id,
                        'chunk_ids' => ['chunk-1'],
                        'source_text' => 'Chunk content about AI'
                    ]
                ],
                [
                    'score' => 0.75,
                    'payload' => [
                        'note_id' => $this->note->id,
                        'audio_id' => null,
                        'chunk_ids' => [],
                        'source_text' => 'Note content about AI'
                    ]
                ]
            ]);

        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);
        
        // Set the constructor properties manually since we're using a mock
        $searchLimitProperty = $reflection->getProperty('searchLimit');
        $searchLimitProperty->setAccessible(true);
        $searchLimitProperty->setValue($vectorService, 10);

        $result = $vectorService->searchDualLevel('AI query');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('chunk_results', $result);
        $this->assertArrayHasKey('note_results', $result);
        $this->assertGreaterThan(0, count($result['chunk_results']));
        $this->assertGreaterThan(0, count($result['note_results']));
    }

    public function test_get_note_level_embedding()
    {
        // Create note-level embedding
        $noteEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => 'Note content',
            'embedding_model' => 'test-model'
        ]);

        // Create audio file for chunk-level embedding
        $audioFile = \App\Models\AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/test.webm',
            'mime_type' => 'audio/webm'
        ]);

        // Create chunk-level embedding (should not be returned)
        $chunkEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'chunk_ids' => ['chunk-1'],
            'source_text' => 'Chunk content',
            'embedding_model' => 'test-model'
        ]);

        $reflection = new \ReflectionClass($this->vectorService);
        $method = $reflection->getMethod('getNoteLevelEmbedding');
        $method->setAccessible(true);

        $result = $method->invoke($this->vectorService, $this->note->id);

        $this->assertNotNull($result);
        $this->assertEquals($noteEmbedding->id, $result->id);
        $this->assertNull($result->audio_id);
        $this->assertEquals([], $result->chunk_ids);
    }

    public function test_delete_note_level_embeddings()
    {
        // Create note-level embedding
        $noteEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => 'Note content',
            'embedding_model' => 'test-model'
        ]);

        // Create audio file for chunk-level embedding
        $audioFile = \App\Models\AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/test.webm',
            'mime_type' => 'audio/webm'
        ]);

        // Create chunk-level embedding (should not be deleted)
        $chunkEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'chunk_ids' => ['chunk-1'],
            'source_text' => 'Chunk content',
            'embedding_model' => 'test-model'
        ]);

        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('deleteEmbedding')
            ->once()
            ->with($noteEmbedding->qdrant_point_id)
            ->andReturn(true);

        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        $method = $reflection->getMethod('deleteNoteLevelEmbeddings');
        $method->setAccessible(true);
        $method->invoke($this->vectorService, $this->note->id);

        // Note-level embedding should be deleted
        $this->assertNull(VectorEmbedding::find($noteEmbedding->id));
        
        // Chunk-level embedding should remain
        $this->assertNotNull(VectorEmbedding::find($chunkEmbedding->id));
    }

    public function test_vector_stats_includes_note_level_count()
    {
        // Create note-level embedding
        VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => null,
            'chunk_ids' => [],
            'source_text' => 'Note content',
            'embedding_model' => 'test-model'
        ]);

        // Create audio file for chunk-level embedding
        $audioFile = \App\Models\AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/test.webm',
            'mime_type' => 'audio/webm'
        ]);

        // Create chunk-level embedding
        VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'chunk_ids' => ['chunk-1'],
            'source_text' => 'Chunk content',
            'embedding_model' => 'test-model'
        ]);

        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('getClusterInfo')
            ->andReturn(['status' => 'ok']);

        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        $stats = $this->vectorService->getVectorStats();

        $this->assertEquals(2, $stats['total_vectors']);
        $this->assertEquals(1, $stats['note_level_vectors']);
        $this->assertEquals(1, $stats['chunk_level_vectors']);
    }

    public function test_chunk_order_affects_aggregation()
    {
        // Create chunks in different order than chunk_order
        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Third chunk.',
            'active_version' => 'dictation',
            'chunk_order' => 3
        ]);

        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'First chunk.',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Second chunk.',
            'active_version' => 'dictation',
            'chunk_order' => 2
        ]);

        $result = $this->vectorService->aggregateNoteContent($this->note->id);

        // Should be ordered by chunk_order, not creation order
        $this->assertEquals('First chunk. Second chunk. Third chunk.', $result['text']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}