<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Note;
use App\Models\AudioFile;
use App\Models\Chunk;
use App\Models\VectorEmbedding;
use App\Services\VectorService;
use App\Services\QdrantService;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class VectorSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Note $note;
    protected VectorService $vectorService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user and note
        $this->user = User::factory()->create();
        $this->note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note for Vector Search'
        ]);
        
        $this->vectorService = new VectorService();
        
        // Set test configuration
        Config::set('larq.gemini_api_key', 'test-key');
    }

    public function test_full_vectorization_workflow()
    {
        // Create audio file and chunks
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/recording.webm',
            'mime_type' => 'audio/webm'
        ]);

        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'dictation_text' => 'This is a test transcription about machine learning and artificial intelligence.',
            'ai_text' => 'This is a test transcription about machine learning and artificial intelligence.',
            'active_version' => 'ai',
            'chunk_order' => 1
        ]);

        // Mock QdrantService for successful vectorization
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn(array_fill(0, 768, 0.1)); // Mock 768-dim embedding
        $mockQdrantService->shouldReceive('storeEmbedding')
            ->andReturn(true);

        // Replace service instance
        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        // Vectorize the content
        $text = $chunk->ai_text;
        $result = $this->vectorService->vectorizeContent(
            $this->note->id,
            $audioFile->id,
            $text,
            [$chunk->id]
        );

        // Verify vectorization success
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['vectors_created']);

        // Verify database record created
        $vectorEmbedding = VectorEmbedding::where('note_id', $this->note->id)->first();
        $this->assertNotNull($vectorEmbedding);
        $this->assertEquals($audioFile->id, $vectorEmbedding->audio_id);
        $this->assertEquals([$chunk->id], $vectorEmbedding->chunk_ids);
        $this->assertEquals($text, $vectorEmbedding->source_text);
    }

    public function test_text_segmentation_with_long_content()
    {
        // Create long text (over 300 words)
        $words = array_fill(0, 400, 'word');
        $longText = implode(' ', $words);

        $segments = $this->vectorService->segmentText($longText);

        // Should create multiple segments
        $this->assertGreaterThan(1, count($segments));

        // First segment should be around 300 words
        $firstSegmentWords = explode(' ', $segments[0]);
        $this->assertLessThanOrEqual(300, count($firstSegmentWords));

        // Verify overlap between segments
        if (count($segments) > 1) {
            $firstWords = explode(' ', $segments[0]);
            $secondWords = explode(' ', $segments[1]);
            
            // Last 50 words of first should match first 50 of second
            $lastWordsFirst = array_slice($firstWords, -50);
            $firstWordsSecond = array_slice($secondWords, 0, 50);
            
            $this->assertEquals($lastWordsFirst, $firstWordsSecond);
        }
    }

    public function test_change_detection_with_levenshtein_distance()
    {
        $vectorService = new VectorService();

        // Test identical text (no change)
        $oldText = 'This is some sample text content.';
        $newText = 'This is some sample text content.';
        $this->assertFalse($vectorService->shouldReembed($oldText, $newText));

        // Test minor changes (under 20% threshold)
        $oldText = 'This is some sample text content.';
        $newText = 'This is some sample text content!'; // Just punctuation
        $this->assertFalse($vectorService->shouldReembed($oldText, $newText));

        // Test significant changes (over 20% threshold)
        $oldText = 'This is some sample text content.';
        $newText = 'This is completely different text with many changes.';
        $this->assertTrue($vectorService->shouldReembed($oldText, $newText));

        // Test empty to content
        $this->assertTrue($vectorService->shouldReembed('', $newText));

        // Test content to empty
        $this->assertTrue($vectorService->shouldReembed($oldText, ''));
    }

    public function test_re_embedding_prevention_when_no_significant_change()
    {
        // Create initial vector embedding
        $text = 'This is some test content for vectorization.';
        
        $vectorEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'source_text' => $text,
            'embedding_model' => 'test-model',
            'text_hash' => hash('sha256', $text)
        ]);

        // The enhanced VectorService uses a different method signature
        // Try to vectorize same content again with minor change
        $result = $this->vectorService->vectorizeContent(
            $this->note->id,
            null,
            $text . '!', // Minor change that should be under threshold
            []
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertEquals('No significant change', $result['reason']);
    }

    public function test_search_functionality_with_mock_results()
    {
        // Create test vector embeddings in database
        $vectorEmbedding1 = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'source_text' => 'Machine learning and artificial intelligence concepts',
            'embedding_model' => 'test-model'
        ]);

        $note2 = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Another Test Note'
        ]);

        $vectorEmbedding2 = VectorEmbedding::create([
            'note_id' => $note2->id,
            'source_text' => 'Natural language processing and deep learning',
            'embedding_model' => 'test-model'
        ]);

        // Mock QdrantService for search
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn(array_fill(0, 768, 0.1));

        // Create a real VectorService with mocked QdrantService
        $vectorService = new VectorService();
        $reflection = new \ReflectionClass($vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($vectorService, $mockQdrantService);

        // Test with empty search results (which is what our mock currently returns)
        $result = $vectorService->searchSimilar('artificial intelligence');

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['total_found']);
        $this->assertIsArray($result['results']);
    }

    public function test_vector_cleanup_on_note_deletion()
    {
        // Create vector embeddings
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

        // Mock QdrantService for deletion
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('deleteEmbedding')
            ->twice()
            ->andReturn(true);

        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        // Delete vectors for note
        $deletedCount = $this->vectorService->deleteVectorsByNote($this->note->id);

        $this->assertEquals(2, $deletedCount);
        $this->assertEquals(0, VectorEmbedding::where('note_id', $this->note->id)->count());
    }

    public function test_vector_cleanup_on_audio_deletion()
    {
        // Create audio file
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/recording.webm',
            'mime_type' => 'audio/webm'
        ]);

        // Create vector embedding linked to audio
        $vectorEmbedding = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'source_text' => 'Audio-linked content',
            'embedding_model' => 'test-model'
        ]);

        // Create another vector not linked to audio
        $vectorEmbedding2 = VectorEmbedding::create([
            'note_id' => $this->note->id,
            'source_text' => 'Text-only content',
            'embedding_model' => 'test-model'
        ]);

        // Mock QdrantService for deletion
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('deleteEmbedding')
            ->once() // Should only delete the audio-linked vector
            ->andReturn(true);

        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        // Delete vectors for audio
        $deletedCount = $this->vectorService->deleteVectorsByAudio($audioFile->id);

        $this->assertEquals(1, $deletedCount);
        $this->assertEquals(0, VectorEmbedding::where('audio_id', $audioFile->id)->count());
        $this->assertEquals(1, VectorEmbedding::where('note_id', $this->note->id)->whereNull('audio_id')->count());
    }

    public function test_vector_statistics_reporting()
    {
        // Create test data
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/recording.webm',
            'mime_type' => 'audio/webm'
        ]);

        VectorEmbedding::create([
            'note_id' => $this->note->id,
            'audio_id' => $audioFile->id,
            'source_text' => 'Audio-linked vector',
            'embedding_model' => 'gemini-model'
        ]);

        VectorEmbedding::create([
            'note_id' => $this->note->id,
            'source_text' => 'Text-only vector',
            'embedding_model' => 'gemini-model'
        ]);

        $note2 = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Second Note'
        ]);

        VectorEmbedding::create([
            'note_id' => $note2->id,
            'source_text' => 'Second note vector',
            'embedding_model' => 'openai-model'
        ]);

        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('getClusterInfo')
            ->andReturn(['status' => 'healthy']);

        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        $stats = $this->vectorService->getVectorStats();

        $this->assertEquals(3, $stats['total_vectors']);
        $this->assertEquals(1, $stats['vectors_with_audio']);
        $this->assertEquals(2, $stats['vectors_text_only']);
        $this->assertEquals(2, $stats['unique_notes_vectorized']);
        $this->assertEquals(1.5, $stats['average_vectors_per_note']); // 3 vectors / 2 notes
        
        $this->assertArrayHasKey('embedding_models', $stats);
        $this->assertEquals(2, $stats['embedding_models']['gemini-model']);
        $this->assertEquals(1, $stats['embedding_models']['openai-model']);
    }

    public function test_vectorization_with_chunk_relationships()
    {
        // Create chunks
        $chunk1 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'First chunk content',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        $chunk2 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Second chunk content',
            'active_version' => 'dictation',
            'chunk_order' => 2
        ]);

        // Mock QdrantService
        $mockQdrantService = Mockery::mock(QdrantService::class);
        $mockQdrantService->shouldReceive('generateEmbedding')
            ->andReturn(array_fill(0, 768, 0.1));
        $mockQdrantService->shouldReceive('storeEmbedding')
            ->andReturn(true);

        $reflection = new \ReflectionClass($this->vectorService);
        $property = $reflection->getProperty('qdrantService');
        $property->setAccessible(true);
        $property->setValue($this->vectorService, $mockQdrantService);

        // Vectorize combined content
        $combinedText = $chunk1->dictation_text . ' ' . $chunk2->dictation_text;
        $result = $this->vectorService->vectorizeContent(
            $this->note->id,
            null,
            $combinedText,
            [$chunk1->id, $chunk2->id]
        );

        $this->assertTrue($result['success']);

        // Verify chunk relationships are stored
        $vectorEmbedding = VectorEmbedding::where('note_id', $this->note->id)->first();
        $this->assertEquals([$chunk1->id, $chunk2->id], $vectorEmbedding->chunk_ids);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}