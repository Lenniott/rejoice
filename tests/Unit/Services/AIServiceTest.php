<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\AIService;
use App\Models\Chunk;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AIServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AIService $aiService;
    protected User $user;
    protected Note $note;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->aiService = new AIService();
        
        // Create test user and note
        $this->user = User::factory()->create();
        $this->note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note for AI Processing'
        ]);
    }

    public function test_is_configured_returns_false_when_no_api_key()
    {
        Config::set('larq.gemini_api_key', null);
        
        $aiService = new AIService();
        
        $this->assertFalse($aiService->isConfigured());
    }

    public function test_is_configured_returns_true_when_api_key_present()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        $aiService = new AIService();
        
        $this->assertTrue($aiService->isConfigured());
    }

    public function test_enhance_text_returns_null_when_not_configured()
    {
        Config::set('larq.gemini_api_key', null);
        
        $aiService = new AIService();
        $result = $aiService->enhanceText('test text');
        
        $this->assertNull($result);
    }

    public function test_enhance_text_returns_null_for_empty_text()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        $aiService = new AIService();
        $result = $aiService->enhanceText('');
        
        $this->assertNull($result);
    }

    public function test_enhance_text_success_flow()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        // Mock successful HTTP response
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'This is the enhanced text with better grammar and clarity.']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);
        
        $aiService = new AIService();
        $result = $aiService->enhanceText('this is bad grammar text');
        
        $this->assertEquals('This is the enhanced text with better grammar and clarity.', $result);
    }

    public function test_enhance_text_handles_api_failure()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        // Mock failed HTTP response
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'API Error'], 500)
        ]);
        
        $aiService = new AIService();
        $result = $aiService->enhanceText('test text');
        
        $this->assertNull($result);
    }

    public function test_enhance_text_handles_malformed_response()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        // Mock malformed response (missing expected structure)
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'unexpected' => 'structure'
            ], 200)
        ]);
        
        $aiService = new AIService();
        $result = $aiService->enhanceText('test text');
        
        $this->assertNull($result);
    }

    public function test_enhance_text_with_context()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Enhanced text with context.']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);
        
        $context = [
            'note_title' => 'Meeting Notes',
            'audio_linked' => true
        ];
        
        $aiService = new AIService();
        $result = $aiService->enhanceText('test text', $context);
        
        $this->assertEquals('Enhanced text with context.', $result);
        
        // Verify that the request included context in the prompt
        Http::assertSent(function ($request) {
            $body = $request->data();
            $prompt = $body['contents'][0]['parts'][0]['text'];
            return str_contains($prompt, 'Meeting Notes') && 
                   str_contains($prompt, 'audio recording');
        });
    }

    public function test_process_chunk_with_invalid_chunk_id()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        $aiService = new AIService();
        $result = $aiService->processChunk('invalid-uuid');
        
        $this->assertFalse($result);
    }

    public function test_process_chunk_success_with_dictation_text()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        // Create chunk with dictation text
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'This is raw dictation with bad grammar',
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);
        
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'This is raw dictation with improved grammar.']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);
        
        $aiService = new AIService();
        $result = $aiService->processChunk($chunk->id);
        
        $this->assertTrue($result);
        
        // Verify chunk was updated
        $chunk->refresh();
        $this->assertEquals('This is raw dictation with improved grammar.', $chunk->ai_text);
        $this->assertEquals('ai', $chunk->active_version);
    }

    public function test_process_chunk_prefers_edited_text_over_dictation()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        // Create chunk with both dictation and edited text
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Original dictation text',
            'edited_text' => 'User edited text',
            'active_version' => 'edited',
            'chunk_order' => 1
        ]);
        
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'AI enhanced: User edited text']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);
        
        $aiService = new AIService();
        $result = $aiService->processChunk($chunk->id);
        
        $this->assertTrue($result);
        
        // Verify the request used edited text, not dictation text
        Http::assertSent(function ($request) {
            $body = $request->data();
            $prompt = $body['contents'][0]['parts'][0]['text'];
            return str_contains($prompt, 'User edited text') && 
                   !str_contains($prompt, 'Original dictation text');
        });
    }

    public function test_process_chunk_with_no_source_text()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        // Create chunk with no text
        $chunk = Chunk::create([
            'note_id' => $this->note->id,
            'active_version' => 'dictation',
            'chunk_order' => 1
        ]);
        
        $aiService = new AIService();
        $result = $aiService->processChunk($chunk->id);
        
        $this->assertFalse($result);
    }

    public function test_batch_process_chunks()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        // Create multiple chunks
        $chunks = [];
        for ($i = 1; $i <= 3; $i++) {
            $chunks[] = Chunk::create([
                'note_id' => $this->note->id,
                'dictation_text' => "Dictation text {$i}",
                'active_version' => 'dictation',
                'chunk_order' => $i
            ]);
        }
        
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Enhanced text']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);
        
        $chunkIds = array_map(fn($chunk) => $chunk->id, $chunks);
        
        $aiService = new AIService();
        $results = $aiService->batchProcessChunks($chunkIds);
        
        $this->assertCount(3, $results);
        $this->assertTrue($results[$chunks[0]->id]);
        $this->assertTrue($results[$chunks[1]->id]);
        $this->assertTrue($results[$chunks[2]->id]);
    }

    public function test_validate_config_with_working_api()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
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
    }

    public function test_validate_config_with_failing_api()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Unauthorized'], 401)
        ]);
        
        $aiService = new AIService();
        $status = $aiService->validateConfig();
        
        $this->assertTrue($status['configured']);
        $this->assertFalse($status['api_accessible']);
        $this->assertStringContainsString('API test failed: 401', $status['last_error']);
    }

    public function test_get_model_info()
    {
        Config::set('larq.gemini_api_key', 'test-api-key');
        
        $aiService = new AIService();
        $info = $aiService->getModelInfo();
        
        $this->assertArrayHasKey('model', $info);
        $this->assertArrayHasKey('base_url', $info);
        $this->assertArrayHasKey('timeout', $info);
        $this->assertArrayHasKey('max_tokens', $info);
        $this->assertArrayHasKey('temperature', $info);
        $this->assertArrayHasKey('configured', $info);
        $this->assertTrue($info['configured']);
    }

    public function test_get_processing_stats()
    {
        // Create some test chunks
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
            'active_version' => 'dictation', // Has AI text but not active
            'chunk_order' => 2
        ]);
        
        $chunk3 = Chunk::create([
            'note_id' => $this->note->id,
            'dictation_text' => 'Text 3',
            'active_version' => 'dictation', // No AI text
            'chunk_order' => 3
        ]);
        
        $aiService = new AIService();
        $stats = $aiService->getProcessingStats();
        
        $this->assertEquals(3, $stats['total_chunks']);
        $this->assertEquals(2, $stats['ai_processed_chunks']); // Chunks with ai_text
        $this->assertEquals(1, $stats['ai_active_chunks']); // Chunks with active_version = 'ai'
        $this->assertEquals(66.67, $stats['processing_rate']); // 2/3 * 100
        $this->assertEquals(33.33, $stats['ai_adoption_rate']); // 1/3 * 100
    }
}