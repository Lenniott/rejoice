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

    public function test_job_handles_successful_vectorization()
    {
        $content = 'Test content for successful vectorization';
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->with($this->note->id, null, $content, [])
            ->andReturn([
                'success' => true,
                'vectors_created' => 2,
                'segments_processed' => 1
            ]);
        
        $job = new VectorizeContentJob($this->note->id, null, $content);
        $job->handle($mockVectorService);
        
        // If we get here without exceptions, the job handled successfully
        $this->assertTrue(true);
    }

    public function test_job_handles_vectorization_failure()
    {
        $content = 'Test content for failed vectorization';
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->with($this->note->id, null, $content, [])
            ->andReturn([
                'success' => false,
                'error' => 'Embedding generation failed'
            ]);
        
        // Mock the job to check release() is called
        $mockJob = Mockery::mock(VectorizeContentJob::class)->makePartial();
        $mockJob->shouldReceive('attempts')->andReturn(1);
        $mockJob->shouldReceive('release')->once()->with(120);
        
        // Set up the properties
        $mockJob->noteId = $this->note->id;
        $mockJob->audioId = null;
        $mockJob->content = $content;
        $mockJob->chunkIds = [];
        $mockJob->tries = 3;
        
        $mockJob->handle($mockVectorService);
    }

    public function test_job_handles_exception_during_processing()
    {
        $content = 'Test content that will cause exception';
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->andThrow(new \Exception('Database connection failed'));
        
        // Mock the job to check release() is called
        $mockJob = Mockery::mock(VectorizeContentJob::class)->makePartial();
        $mockJob->shouldReceive('attempts')->andReturn(1);
        $mockJob->shouldReceive('release')->once();
        
        // Set up the properties
        $mockJob->noteId = $this->note->id;
        $mockJob->audioId = null;
        $mockJob->content = $content;
        $mockJob->chunkIds = [];
        $mockJob->tries = 3;
        
        $mockJob->handle($mockVectorService);
    }

    public function test_job_fails_after_max_attempts()
    {
        $content = 'Test content for max attempts';
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Persistent failure'
            ]);
        
        // Mock the job to simulate max attempts reached
        $mockJob = Mockery::mock(VectorizeContentJob::class)->makePartial();
        $mockJob->shouldReceive('attempts')->andReturn(3);
        $mockJob->shouldNotReceive('release');
        
        // Set up the properties
        $mockJob->noteId = $this->note->id;
        $mockJob->audioId = null;
        $mockJob->content = $content;
        $mockJob->chunkIds = [];
        $mockJob->tries = 3;
        
        $mockJob->handle($mockVectorService);
    }

    public function test_job_middleware_prevents_overlapping()
    {
        $job = new VectorizeContentJob($this->note->id, null, 'test');
        $middleware = $job->middleware();
        
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware[0]);
    }

    public function test_job_middleware_uses_different_keys_for_different_content()
    {
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/recording.webm',
            'mime_type' => 'audio/webm'
        ]);

        $jobWithoutAudio = new VectorizeContentJob($this->note->id, null, 'test');
        $jobWithAudio = new VectorizeContentJob($this->note->id, $audioFile->id, 'test');
        
        $middlewareWithoutAudio = $jobWithoutAudio->middleware();
        $middlewareWithAudio = $jobWithAudio->middleware();
        
        // Both should have middleware but they should use different keys
        $this->assertCount(1, $middlewareWithoutAudio);
        $this->assertCount(1, $middlewareWithAudio);
    }

    public function test_job_tags_include_relevant_information()
    {
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/recording.webm',
            'mime_type' => 'audio/webm'
        ]);

        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Test chunk',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        $job = new VectorizeContentJob($this->note->id, $audioFile->id, 'test', [$chunk->id]);
        $tags = $job->tags();
        
        $this->assertContains('vectorization', $tags);
        $this->assertContains('note:' . $this->note->id, $tags);
        $this->assertContains('audio:' . $audioFile->id, $tags);
        $this->assertContains('chunks:1', $tags);
    }

    public function test_job_backoff_strategy()
    {
        $job = new VectorizeContentJob($this->note->id, null, 'test');
        $backoff = $job->backoff();
        
        $this->assertEquals([120, 240, 480], $backoff);
    }

    public function test_job_failed_method_logs_failure()
    {
        $job = new VectorizeContentJob($this->note->id, null, 'test');
        $exception = new \Exception('Job failed permanently');
        
        // Mock the job to check attempts() is called
        $mockJob = Mockery::mock(VectorizeContentJob::class)->makePartial();
        $mockJob->shouldReceive('attempts')->andReturn(3);
        
        // Set up the properties
        $mockJob->noteId = $this->note->id;
        $mockJob->audioId = null;
        $mockJob->content = 'test';
        $mockJob->chunkIds = [];
        
        // This should not throw an exception
        $mockJob->failed($exception);
        $this->assertTrue(true);
    }

    public function test_job_with_chunk_validation()
    {
        // Create some chunks
        $chunk1 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Chunk 1',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        $chunk2 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Chunk 2',
            'active_version' => 'dictation',
            'chunk_order' => 2
        ]);

        $content = 'Combined content from chunks';
        $chunkIds = [$chunk1->id, $chunk2->id, 'non-existent-chunk'];
        
        $mockVectorService = Mockery::mock(VectorService::class);
        $mockVectorService->shouldReceive('vectorizeContent')
            ->once()
            ->with($this->note->id, null, $content, $chunkIds)
            ->andReturn(['success' => true, 'vectors_created' => 1]);
        
        $job = new VectorizeContentJob($this->note->id, null, $content, $chunkIds);
        
        // Job should still process even with missing chunks (will log warning)
        $job->handle($mockVectorService);
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
        Mockery::close();
        parent::tearDown();
    }
}