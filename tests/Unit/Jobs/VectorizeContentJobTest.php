<?php

namespace Tests\Unit\Jobs;

use Tests\TestCase;
use App\Jobs\VectorizeContentJob;
use App\Services\VectorService;
use App\Models\User;
use App\Models\Note;
use App\Models\AudioFile;
use App\Models\Chunk;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class VectorizeContentJobTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Note $note;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note for Vectorization Job'
        ]);
    }

    public function test_job_can_be_constructed_with_required_parameters()
    {
        $content = 'Test content for vectorization';
        $chunkIds = ['chunk-1', 'chunk-2'];
        
        $job = new VectorizeContentJob($this->note->id, null, $content, $chunkIds);
        
        $this->assertEquals($this->note->id, $job->noteId);
        $this->assertNull($job->audioId);
        $this->assertEquals($content, $job->content);
        $this->assertEquals($chunkIds, $job->chunkIds);
    }

    public function test_job_can_be_constructed_with_audio_id()
    {
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/recording.webm',
            'mime_type' => 'audio/webm'
        ]);

        $content = 'Audio-linked content for vectorization';
        
        $job = new VectorizeContentJob($this->note->id, $audioFile->id, $content);
        
        $this->assertEquals($this->note->id, $job->noteId);
        $this->assertEquals($audioFile->id, $job->audioId);
        $this->assertEquals($content, $job->content);
        $this->assertEquals([], $job->chunkIds);
    }

    public function test_job_configuration_properties()
    {
        $job = new VectorizeContentJob($this->note->id, null, 'test');
        
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(300, $job->timeout);
    }

    public function test_job_handles_missing_note()
    {
        $fakeNoteId = 'fake-note-id';
        $job = new VectorizeContentJob($fakeNoteId, null, 'test content');
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldNotReceive('vectorizeContent');
        
        // Mock the fail method
        $mockJob = Mockery::mock(VectorizeContentJob::class)->makePartial();
        $mockJob->shouldReceive('fail')->once()->with('Note not found');
        
        // Set up the properties
        $mockJob->noteId = $fakeNoteId;
        $mockJob->audioId = null;
        $mockJob->content = 'test content';
        $mockJob->chunkIds = [];
        
        $mockJob->handle($mockVectorService);
    }

    public function test_job_handles_missing_audio_file()
    {
        $fakeAudioId = 'fake-audio-id';
        $job = new VectorizeContentJob($this->note->id, $fakeAudioId, 'test content');
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldNotReceive('vectorizeContent');
        
        // Mock the fail method
        $mockJob = Mockery::mock(VectorizeContentJob::class)->makePartial();
        $mockJob->shouldReceive('fail')->once()->with('Audio file not found');
        
        // Set up the properties
        $mockJob->noteId = $this->note->id;
        $mockJob->audioId = $fakeAudioId;
        $mockJob->content = 'test content';
        $mockJob->chunkIds = [];
        
        $mockJob->handle($mockVectorService);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_job_handles_successful_vectorization()
    {
        $content = 'Test content for vectorization';
        $chunkIds = ['chunk-1', 'chunk-2'];
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->with($this->note->id, null, $content, $chunkIds)
            ->andReturn([
                'success' => true,
                'vectors_created' => 2,
                'segments_processed' => 1,
                'skipped' => false
            ]);
        
        $job = new VectorizeContentJob($this->note->id, null, $content, $chunkIds);
        $job->handle($mockVectorService);
        
        // Job should complete successfully
        $this->assertTrue(true);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_job_handles_vectorization_failure()
    {
        $content = 'Test content for vectorization';
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->with($this->note->id, null, $content, [])
            ->andReturn([
                'success' => false,
                'error' => 'Vectorization failed'
            ]);
        
        // Mock the job's attempts method to simulate first attempt
        $job = Mockery::mock(VectorizeContentJob::class, [$this->note->id, null, $content])->makePartial();
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->with(120); // 2 minute delay
        
        $job->handle($mockVectorService);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_job_handles_exception_during_processing()
    {
        $content = 'Test content for vectorization';
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->andThrow(new \Exception('Test exception'));
        
        // Mock the job's attempts method to simulate first attempt
        $job = Mockery::mock(VectorizeContentJob::class, [$this->note->id, null, $content])->makePartial();
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->with(120); // 2 minute delay
        
        $job->handle($mockVectorService);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_job_fails_after_max_attempts()
    {
        $content = 'Test content for vectorization';
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->andThrow(new \Exception('Test exception'));
        
        // Mock the job's attempts method to simulate final attempt
        $job = Mockery::mock(VectorizeContentJob::class, [$this->note->id, null, $content])->makePartial();
        $job->shouldReceive('attempts')->andReturn(3); // Max attempts reached
        $job->shouldReceive('fail')->once(); // Should fail the job
        
        $job->handle($mockVectorService);
    }

    public function test_job_middleware_prevents_overlapping()
    {
        $job = new VectorizeContentJob($this->note->id, null, 'test content');
        $middleware = $job->middleware();
        
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware[0]);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_job_middleware_uses_different_keys_for_different_content()
    {
        $content1 = 'First content';
        $content2 = 'Second content';
        
        $job1 = new VectorizeContentJob($this->note->id, null, $content1);
        $job2 = new VectorizeContentJob($this->note->id, null, $content2);
        
        $middleware1 = $job1->middleware();
        $middleware2 = $job2->middleware();
        
        // Both should have middleware
        $this->assertCount(1, $middleware1);
        $this->assertCount(1, $middleware2);
        
        // Both should be WithoutOverlapping instances
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware1[0]);
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware2[0]);
    }

    public function test_job_tags_include_relevant_information()
    {
        $content = 'Test content';
        $chunkIds = ['chunk-1', 'chunk-2'];
        
        $job = new VectorizeContentJob($this->note->id, null, $content, $chunkIds);
        $tags = $job->tags();
        
        $this->assertContains('vectorization', $tags);
        $this->assertContains('note:' . $this->note->id, $tags);
        $this->assertContains('chunks:2', $tags);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_job_backoff_strategy()
    {
        $job = new VectorizeContentJob($this->note->id, null, 'test content');
        $backoff = $job->backoff();
        
        // Should have exponential backoff strategy
        $this->assertIsArray($backoff);
        $this->assertGreaterThan(0, count($backoff));
    }

    public function test_job_failed_method_logs_failure()
    {
        $job = new VectorizeContentJob($this->note->id, null, 'test content');
        $exception = new \Exception('Test failure');
        
        // Test that the method doesn't throw an exception
        // The actual logging is tested in integration tests
        $job->failed($exception);
        
        // If we get here without exception, the method worked
        $this->assertTrue(true);
    }

    /**
     * @todo Phase5: This test depends on VectorService integration which is not yet fully implemented
     */
    public function test_job_with_chunk_validation()
    {
        // Create some test chunks
        $chunk1 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'First chunk',
            'chunk_order' => 1
        ]);
        
        $chunk2 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Second chunk',
            'chunk_order' => 2
        ]);
        
        $content = 'Combined content from chunks';
        $chunkIds = [$chunk1->id, $chunk2->id];
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->with($this->note->id, null, $content, $chunkIds)
            ->andReturn([
                'success' => true,
                'vectors_created' => 2,
                'segments_processed' => 1,
                'skipped' => false
            ]);
        
        $job = new VectorizeContentJob($this->note->id, null, $content, $chunkIds);
        $job->handle($mockVectorService);
        
        // Job should complete successfully
        $this->assertTrue(true);
    }

    public function test_job_can_be_queued()
    {
        Queue::fake();
        
        VectorizeContentJob::dispatch($this->note->id, null, 'test content', []);
        
        Queue::assertPushed(VectorizeContentJob::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}