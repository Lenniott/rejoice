<?php

namespace Tests\Unit\Jobs;

use Tests\TestCase;
use App\Jobs\ProcessChunkWithAI;
use App\Services\AIService;
use App\Models\Chunk;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class ProcessChunkWithAITest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Note $note;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user and note
        $this->user = User::factory()->create();
        $this->note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note for AI Job Processing'
        ]);
    }

    public function test_job_can_be_constructed_with_chunk_id()
    {
        $chunkId = 'test-chunk-id';
        $job = new ProcessChunkWithAI($chunkId);
        
        $this->assertEquals($chunkId, $job->chunkId);
    }

    public function test_job_configuration()
    {
        $job = new ProcessChunkWithAI('test-chunk-id');
        
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(120, $job->timeout);
    }

    public function test_job_handles_missing_chunk()
    {
        $aiService = Mockery::mock(AIService::class);
        $job = Mockery::mock(ProcessChunkWithAI::class, ['non-existent-chunk-id'])->makePartial();
        $job->shouldReceive('fail')->once()->with('Chunk not found');
        
        $job->handle($aiService);
    }

    public function test_job_skips_processing_when_ai_not_configured()
    {
        // Create a chunk
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);
        
        // Mock AI service as not configured
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('isConfigured')->once()->andReturn(false);
        $aiService->shouldNotReceive('processChunk');
        
        $job = new ProcessChunkWithAI($chunk->id);
        $job->handle($aiService);
        
        // Job should complete without error, chunk should remain unchanged
        $chunk->refresh();
        $this->assertNull($chunk->ai_text);
        $this->assertEquals('dictation', $chunk->active_version);
    }

    public function test_job_skips_processing_when_chunk_already_has_ai_text()
    {
        // Create a chunk with existing AI text
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Test dictation text',
            'ai_text' => 'Existing AI text',
            'active_version' => 'ai',
            'chunk_order' => 1
        ]);
        
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('isConfigured')->once()->andReturn(true);
        $aiService->shouldNotReceive('processChunk');
        
        $job = new ProcessChunkWithAI($chunk->id);
        $job->handle($aiService);
        
        // Chunk should remain unchanged
        $chunk->refresh();
        $this->assertEquals('Existing AI text', $chunk->ai_text);
        $this->assertEquals('ai', $chunk->active_version);
    }

    public function test_job_processes_chunk_successfully()
    {
        // Create a chunk without AI text
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);
        
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('isConfigured')->once()->andReturn(true);
        $aiService->shouldReceive('processChunk')->once()->with($chunk->id)->andReturn(true);
        
        $job = new ProcessChunkWithAI($chunk->id);
        $job->handle($aiService);
        
        // Job should complete successfully
        $this->assertTrue(true); // If we get here, job completed without exception
    }

    public function test_job_handles_processing_failure()
    {
        // Create a chunk
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);
        
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('isConfigured')->once()->andReturn(true);
        $aiService->shouldReceive('processChunk')->once()->with($chunk->id)->andReturn(false);
        
        // Mock the job's attempts method to simulate first attempt
        $job = Mockery::mock(ProcessChunkWithAI::class, [$chunk->id])->makePartial();
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->with(60);
        
        $job->handle($aiService);
    }

    public function test_job_gives_up_after_max_attempts()
    {
        // Create a chunk
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);
        
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('isConfigured')->once()->andReturn(true);
        $aiService->shouldReceive('processChunk')->once()->with($chunk->id)->andReturn(false);
        
        // Mock the job's attempts method to simulate final attempt
        $job = Mockery::mock(ProcessChunkWithAI::class, [$chunk->id])->makePartial();
        $job->shouldReceive('attempts')->andReturn(3); // Final attempt
        $job->shouldNotReceive('release');
        
        $job->handle($aiService);
    }

    public function test_job_handles_exception_with_retry()
    {
        // Create a chunk
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);
        
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('isConfigured')->once()->andReturn(true);
        $aiService->shouldReceive('processChunk')->once()->with($chunk->id)->andThrow(new \Exception('API Error'));
        
        // Mock the job's attempts method to simulate first attempt
        $job = Mockery::mock(ProcessChunkWithAI::class, [$chunk->id])->makePartial();
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->with(60); // Should retry with 60 second delay
        
        $job->handle($aiService);
    }

    public function test_job_handles_exception_on_final_attempt()
    {
        // Create a chunk
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);
        
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('isConfigured')->once()->andReturn(true);
        $aiService->shouldReceive('processChunk')->once()->with($chunk->id)->andThrow(new \Exception('API Error'));
        
        // Mock the job's attempts method to simulate final attempt
        $job = Mockery::mock(ProcessChunkWithAI::class, [$chunk->id])->makePartial();
        $job->shouldReceive('attempts')->andReturn(3); // Final attempt
        $job->shouldReceive('fail')->once()->with(Mockery::type(\Exception::class));
        
        $job->handle($aiService);
    }

    public function test_job_middleware_prevents_overlapping()
    {
        $chunkId = 'test-chunk-id';
        $job = new ProcessChunkWithAI($chunkId);
        
        $middleware = $job->middleware();
        
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware[0]);
    }

    public function test_job_tags()
    {
        $chunkId = 'test-chunk-id';
        $job = new ProcessChunkWithAI($chunkId);
        
        $tags = $job->tags();
        
        $this->assertContains('ai-processing', $tags);
        $this->assertContains('chunk:test-chunk-id', $tags);
    }

    public function test_job_should_override_existing_logic()
    {
        $job = new ProcessChunkWithAI('test-chunk-id');
        
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('shouldOverrideExisting');
        $method->setAccessible(true);
        
        $result = $method->invoke($job);
        
        // Current implementation should return false
        $this->assertFalse($result);
    }

    public function test_job_failed_method_logs_failure()
    {
        $chunkId = 'test-chunk-id';
        $job = new ProcessChunkWithAI($chunkId);
        $exception = new \Exception('Test failure');
        
        // Should not throw exception when failed() is called
        $job->failed($exception);
        
        // If we get here, the method handled the failure gracefully
        $this->assertTrue(true);
    }

    public function test_job_can_be_queued()
    {
        Queue::fake();
        
        $chunkId = 'test-chunk-id';
        
        ProcessChunkWithAI::dispatch($chunkId);
        
        Queue::assertPushed(ProcessChunkWithAI::class, function ($job) use ($chunkId) {
            return $job->chunkId === $chunkId;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}