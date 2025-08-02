<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Note;
use App\Models\Chunk;
use App\Services\AIService;
use App\Jobs\ProcessChunkWithAI;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChunkAIProcessingTest extends TestCase
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
            'title' => 'Test Note for AI Processing Integration'
        ]);
        
        // Set up AI configuration for testing
        Config::set('larq.gemini_api_key', 'test-api-key');
    }

    public function test_full_ai_processing_workflow()
    {
        // Create a chunk with dictation text
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'this is bad grammar text that need improving',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Mock successful Gemini API response
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'This is improved grammar text that needs enhancing.']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        // Process the chunk with AI
        $aiService = new AIService();
        $success = $aiService->processChunk($chunk->id);

        // Verify processing was successful
        $this->assertTrue($success);

        // Verify chunk was updated correctly
        $chunk->refresh();
        $this->assertEquals('This is improved grammar text that needs enhancing.', $chunk->ai_text);
        $this->assertEquals('ai', $chunk->active_version);
        $this->assertEquals('this is bad grammar text that need improving', $chunk->dictation_text); // Original preserved
    }

    public function test_chunk_processing_with_context()
    {
        // Create an audio file first to satisfy foreign key constraint
        $audioFile = \App\Models\AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/test/audio.webm',
            'mime_type' => 'audio/webm'
        ]);
        
        // Create a chunk linked to the audio file
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'meeting notes about project alpha',
            'active_version' => 'dictation',
            'chunk_order' => 1,
            'audio_id' => $audioFile->id
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Meeting notes about Project Alpha.']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $aiService = new AIService();
        $success = $aiService->processChunk($chunk->id);

        $this->assertTrue($success);

        // Verify the API request included proper context
        Http::assertSent(function ($request) {
            $body = $request->data();
            $prompt = $body['contents'][0]['parts'][0]['text'];
            
            return str_contains($prompt, 'Test Note for AI Processing Integration') &&
                   str_contains($prompt, 'audio recording') &&
                   str_contains($prompt, 'meeting notes about project alpha');
        });
    }

    public function test_processing_prioritizes_edited_text_over_dictation()
    {
        // Create chunk with both dictation and edited text
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'original dictation text',
            'edited_text' => 'user edited this text manually',
            'active_version' => 'edited',
            'chunk_order' => 1
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'User edited this text manually with AI enhancement.']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $aiService = new AIService();
        $success = $aiService->processChunk($chunk->id);

        $this->assertTrue($success);

        // Verify request used edited text as source
        Http::assertSent(function ($request) {
            $body = $request->data();
            $prompt = $body['contents'][0]['parts'][0]['text'];
            
            return str_contains($prompt, 'user edited this text manually') &&
                   !str_contains($prompt, 'original dictation text');
        });
    }

    public function test_batch_processing_multiple_chunks()
    {
        // Create multiple chunks
        $chunks = [];
        for ($i = 1; $i <= 3; $i++) {
            $chunks[] = Chunk::create([
                'note_id' => $this->note->id,
                'dictation_text' => "Dictation text number {$i}",
                'active_version' => 'dictation',
                'chunk_order' => $i
            ]);
        }

        // Mock successful responses for all requests
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Enhanced dictation text.']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $chunkIds = array_map(fn($chunk) => $chunk->id, $chunks);

        $aiService = new AIService();
        $results = $aiService->batchProcessChunks($chunkIds);

        // Verify all chunks were processed successfully
        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertTrue($result);
        }

        // Verify all chunks were updated
        foreach ($chunks as $chunk) {
            $chunk->refresh();
            $this->assertEquals('Enhanced dictation text.', $chunk->ai_text);
            $this->assertEquals('ai', $chunk->active_version);
        }
    }

    public function test_processing_handles_api_failures_gracefully()
    {
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Mock API failure
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit exceeded'], 429)
        ]);

        $aiService = new AIService();
        $success = $aiService->processChunk($chunk->id);

        // Processing should fail gracefully
        $this->assertFalse($success);

        // Chunk should remain unchanged
        $chunk->refresh();
        $this->assertNull($chunk->ai_text);
        $this->assertEquals('dictation', $chunk->active_version);
    }

    public function test_processing_handles_malformed_api_responses()
    {
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Mock malformed response
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'unexpected' => 'structure',
                'missing' => 'expected fields'
            ], 200)
        ]);

        $aiService = new AIService();
        $success = $aiService->processChunk($chunk->id);

        $this->assertFalse($success);

        // Chunk should remain unchanged
        $chunk->refresh();
        $this->assertNull($chunk->ai_text);
        $this->assertEquals('dictation', $chunk->active_version);
    }

    public function test_background_job_integration()
    {
        Queue::fake();

        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'test dictation for background processing',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        // Dispatch the job
        ProcessChunkWithAI::dispatch($chunk->id);

        // Verify job was queued
        Queue::assertPushed(ProcessChunkWithAI::class, function ($job) use ($chunk) {
            return $job->chunkId === $chunk->id;
        });
    }

    public function test_processing_stats_calculation()
    {
        // Create chunks in various states
        $chunk1 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Text 1',
            'ai_text' => 'Enhanced text 1',
            'active_version' => 'ai',
            'chunk_order' => 1
        ]);

        $chunk2 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Text 2',
            'ai_text' => 'Enhanced text 2',
            'active_version' => 'dictation', // Has AI text but user preferred original
            'chunk_order' => 2
        ]);

        $chunk3 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Text 3',
            'active_version' => 'dictation', // No AI processing yet
            'chunk_order' => 3
        ]);

        $aiService = new AIService();
        $stats = $aiService->getProcessingStats();

        $this->assertEquals(3, $stats['total_chunks']);
        $this->assertEquals(2, $stats['ai_processed_chunks']); // Chunks with ai_text
        $this->assertEquals(1, $stats['ai_active_chunks']); // Chunks using AI as active version
        $this->assertEquals(66.67, $stats['processing_rate']); // 2/3 processed
        $this->assertEquals(33.33, $stats['ai_adoption_rate']); // 1/3 using AI
    }

    public function test_ai_service_configuration_validation()
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Test response']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $aiService = new AIService();
        $status = $aiService->validateConfig();

        $this->assertTrue($status['configured']);
        $this->assertTrue($status['api_accessible']);
        $this->assertNull($status['last_error']);
        $this->assertNotEmpty($status['model']);
    }

    public function test_processing_with_no_api_key_configured()
    {
        Config::set('larq.gemini_api_key', null);

        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'test dictation text',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        $aiService = new AIService();
        $success = $aiService->processChunk($chunk->id);

        // Processing should fail gracefully when not configured
        $this->assertFalse($success);
        $this->assertFalse($aiService->isConfigured());
    }

    public function test_model_info_retrieval()
    {
        $aiService = new AIService();
        $info = $aiService->getModelInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('model', $info);
        $this->assertArrayHasKey('base_url', $info);
        $this->assertArrayHasKey('timeout', $info);
        $this->assertArrayHasKey('max_tokens', $info);
        $this->assertArrayHasKey('temperature', $info);
        $this->assertArrayHasKey('configured', $info);
    }

    public function test_chunk_relationships_preserved_during_processing()
    {
        // Create chunk with relationships
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'test with relationships',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Test with improved relationships.']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $aiService = new AIService();
        $success = $aiService->processChunk($chunk->id);

        $this->assertTrue($success);

        // Verify relationships are preserved
        $chunk->refresh();
        $this->assertEquals($this->note->id, $chunk->note_id);
        $this->assertEquals($this->note->id, $chunk->note->id);
        $this->assertEquals($this->user->id, $chunk->note->user_id);
        $this->assertEquals(1, $chunk->chunk_order);
    }
}