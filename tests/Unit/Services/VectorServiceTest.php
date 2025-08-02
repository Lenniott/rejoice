<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\VectorService;
use App\Services\QdrantService;
use App\Models\VectorEmbedding;
use App\Models\Note;
use App\Models\AudioFile;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class VectorServiceTest extends TestCase
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
            'title' => 'Test Note for Vector Processing'
        ]);
    }

    public function test_segment_text_short_text_returns_single_segment()
    {
        $text = 'This is a short text that should not be segmented.';
        
        $segments = $this->vectorService->segmentText($text);
        
        $this->assertCount(1, $segments);
        $this->assertEquals($text, $segments[0]);
    }

    public function test_segment_text_long_text_creates_multiple_segments()
    {
        // Create text longer than 300 words
        $words = array_fill(0, 350, 'word');
        $text = implode(' ', $words);
        
        $segments = $this->vectorService->segmentText($text);
        
        $this->assertGreaterThan(1, count($segments));
        
        // Check first segment has approximately 300 words
        $firstSegmentWords = explode(' ', $segments[0]);
        $this->assertLessThanOrEqual(300, count($firstSegmentWords));
        
        // Check overlap exists between segments if multiple segments
        if (count($segments) > 1) {
            $firstWords = explode(' ', $segments[0]);
            $secondWords = explode(' ', $segments[1]);
            
            // Last 50 words of first segment should overlap with first 50 words of second
            $lastWordsFirst = array_slice($firstWords, -50);
            $firstWordsSecond = array_slice($secondWords, 0, 50);
            
            $this->assertEquals($lastWordsFirst, $firstWordsSecond);
        }
    }

    public function test_segment_text_with_custom_parameters()
    {
        Config::set('larq.vector_segment_max_words', 100);
        Config::set('larq.vector_segment_overlap_words', 20);
        
        // Recreate service to pick up new config
        $vectorService = new VectorService();
        
        // Create text longer than 100 words
        $words = array_fill(0, 150, 'word');
        $text = implode(' ', $words);
        
        $segments = $vectorService->segmentText($text);
        
        $this->assertGreaterThan(1, count($segments));
        
        // Check segment size
        $firstSegmentWords = explode(' ', $segments[0]);
        $this->assertLessThanOrEqual(100, count($firstSegmentWords));
    }

    public function test_should_reembed_with_no_changes()
    {
        $oldText = 'This is some text content.';
        $newText = 'This is some text content.';
        
        $result = $this->vectorService->shouldReembed($oldText, $newText);
        
        $this->assertFalse($result);
    }

    public function test_should_reembed_with_small_changes()
    {
        $oldText = 'This is some text content.';
        $newText = 'This is some text content!'; // Just added punctuation
        
        $result = $this->vectorService->shouldReembed($oldText, $newText);
        
        $this->assertFalse($result); // Should be under 20% threshold
    }

    public function test_should_reembed_with_significant_changes()
    {
        $oldText = 'This is some text content.';
        $newText = 'This is completely different text with many changes.';
        
        $result = $this->vectorService->shouldReembed($oldText, $newText);
        
        $this->assertTrue($result); // Should exceed 20% threshold
    }

    public function test_should_reembed_with_empty_old_text()
    {
        $oldText = '';
        $newText = 'This is new text.';
        
        $result = $this->vectorService->shouldReembed($oldText, $newText);
        
        $this->assertTrue($result);
    }

    public function test_should_reembed_with_empty_new_text()
    {
        $oldText = 'This is old text.';
        $newText = '';
        
        $result = $this->vectorService->shouldReembed($oldText, $newText);
        
        $this->assertTrue($result);
    }

    public function test_vectorize_content_with_empty_text()
    {
        $result = $this->vectorService->vectorizeContent($this->note->id, null, '');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Empty text content', $result['error']);
    }

    public function test_vectorize_content_success_flow()
    {
        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn([0.1, 0.2, 0.3]); // Mock embedding
        $mockQdrantService->shouldReceive('storeEmbedding')
            ->andReturn(true);
        
        // Replace the service instance
        $vectorService = new VectorService();
        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);
        
        $text = 'This is some test content for vectorization.';
        $result = $vectorService->vectorizeContent($this->note->id, null, $text, []);
        
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['vectors_created']);
    }

    public function test_search_similar_with_empty_query()
    {
        $result = $this->vectorService->searchSimilar('');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Empty query', $result['error']);
    }

    public function test_search_similar_success_flow()
    {
        // Create some test vector embeddings
        $vectorEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'source_text' => 'Test content for search',
            'embedding_model' => 'test-model'
        ]);
        
        // Mock QdrantService for search
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn([0.1, 0.2, 0.3]);
        
        // Create a VectorService instance and inject the mock
        $vectorService = new VectorService();
        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);
        
        // Test search with empty results (which is normal for mock)
        $result = $vectorService->searchSimilar('test query');
        
        // Should succeed even with empty results
        $this->assertTrue($result['success']);
        $this->assertIsArray($result['results']);
        $this->assertEquals(0, $result['total_found']);
    }

    public function test_delete_vectors_by_note()
    {
        // Create test vector embeddings
        $vectorEmbedding1 = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'source_text' => 'Test content 1',
            'embedding_model' => 'test-model'
        ]);
        
        $vectorEmbedding2 = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'source_text' => 'Test content 2',
            'embedding_model' => 'test-model'
        ]);
        
        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('deleteEmbedding')
            ->twice()
            ->andReturn(true);
        
        $vectorService = new VectorService();
        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);
        
        $deletedCount = $vectorService->deleteVectorsByNote($this->note->id);
        
        $this->assertEquals(2, $deletedCount);
        $this->assertEquals(0, VectorEmbedding::where('note_id', $this->note->id)->count());
    }

    public function test_delete_vectors_by_audio()
    {
        // Create audio file
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'test/path.webm',
            'mime_type' => 'audio/webm'
        ]);
        
        // Create vector embedding linked to audio
        $vectorEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'source_text' => 'Test audio content',
            'embedding_model' => 'test-model'
        ]);
        
        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('deleteEmbedding')
            ->once()
            ->andReturn(true);
        
        $vectorService = new VectorService();
        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);
        
        $deletedCount = $vectorService->deleteVectorsByAudio($audioFile->id);
        
        $this->assertEquals(1, $deletedCount);
        $this->assertEquals(0, VectorEmbedding::where('audio_id', $audioFile->id)->count());
    }

    public function test_get_vector_stats()
    {
        // Create test data
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'test/path.webm',
            'mime_type' => 'audio/webm'
        ]);
        
        VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'source_text' => 'Test content with audio',
            'embedding_model' => 'gemini-model'
        ]);
        
        VectorEmbedding::create([
            'note_id' => $this->note->id,
            'source_text' => 'Test content text only',
            'embedding_model' => 'gemini-model'
        ]);
        
        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('getClusterInfo')
            ->andReturn(['status' => 'ok']);
        
        $vectorService = new VectorService();
        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);
        
        $stats = $vectorService->getVectorStats();
        
        $this->assertEquals(2, $stats['total_vectors']);
        $this->assertEquals(1, $stats['vectors_with_audio']);
        $this->assertEquals(1, $stats['chunk_level_vectors']);
        $this->assertEquals(1, $stats['unique_notes_vectorized']);
        $this->assertEquals(2.0, $stats['average_vectors_per_note']);
        $this->assertArrayHasKey('embedding_models', $stats);
    }

    public function test_change_threshold_configuration()
    {
        Config::set('larq.vector_similarity_threshold', 0.5);
        
        $vectorService = new VectorService();
        
        // Test with change that should be under new 50% threshold
        $oldText = 'This is some text content.';
        $newText = 'This is some different content.'; // About 25% change
        
        $result = $vectorService->shouldReembed($oldText, $newText);
        
        $this->assertFalse($result); // Should be under 50% threshold
    }

    public function test_levenshtein_distance_calculation()
    {
        $oldText = 'hello world';
        $newText = 'hello earth';
        
        // This should calculate Levenshtein distance correctly
        $result = $this->vectorService->shouldReembed($oldText, $newText);
        
        // Distance is 5 characters (world->earth), max length is 11
        // Similarity = 5/11 = ~0.45, which is > 0.2 threshold
        $this->assertTrue($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}