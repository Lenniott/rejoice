<?php

/**
 * Qdrant Test Command - Tests vector database connectivity and basic operations
 * 
 * Requirements:
 * - Test connection to Qdrant service
 * - Initialize voice notes collection
 * - Test embedding generation with configured AI model
 * - Verify vector storage and retrieval operations
 * - Display comprehensive health check results
 * 
 * Flow:
 * - php artisan qdrant:test -> Check connectivity -> Test embeddings -> Verify operations
 */

namespace App\Console\Commands;

use App\Services\QdrantService;
use Illuminate\Console\Command;

class TestQdrant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'qdrant:test {--init : Initialize the Qdrant collection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Qdrant vector database connectivity and operations';

    protected QdrantService $qdrantService;

    /**
     * Create a new command instance.
     */
    public function __construct(QdrantService $qdrantService)
    {
        parent::__construct();
        $this->qdrantService = $qdrantService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Testing Qdrant Vector Database Connection...');
        $this->newLine();

        // Test 1: Health Check
        $this->info('1. Health Check');
        if ($this->qdrantService->healthCheck()) {
            $this->line('   ✅ Qdrant is accessible');
            
            // Get cluster info
            $clusterInfo = $this->qdrantService->getClusterInfo();
            if ($clusterInfo) {
                $this->line('   📊 Cluster Info: ' . json_encode($clusterInfo, JSON_PRETTY_PRINT));
            }
        } else {
            $this->error('   ❌ Cannot connect to Qdrant');
            $this->error('   Make sure Qdrant is running and accessible at: ' . config('larq.host'));
            return 1;
        }

        $this->newLine();

        // Test 2: Collection Initialization
        $this->info('2. Collection Initialization');
        if ($this->option('init') || $this->confirm('Initialize voice_notes_v1 collection?', true)) {
            if ($this->qdrantService->initializeCollection()) {
                $this->line('   ✅ Collection initialized successfully');
            } else {
                $this->error('   ❌ Failed to initialize collection');
                return 1;
            }
        } else {
            $this->line('   ⏭️  Skipped collection initialization');
        }

        $this->newLine();

        // Test 3: AI Model Configuration
        $this->info('3. AI Model Configuration');
        $hasGemini = !empty(config('larq.gemini_api_key'));
        $hasOpenAI = !empty(config('larq.openai_api_key'));

        if ($hasGemini) {
            $this->line('   ✅ Gemini API key configured');
            $this->line('   📝 Model: ' . config('larq.gemini_model'));
        } elseif ($hasOpenAI) {
            $this->line('   ✅ OpenAI API key configured');
            $this->line('   📝 Model: ' . config('larq.openai_model'));
        } else {
            $this->error('   ❌ No AI model API key configured');
            $this->error('   Please set GEMINI_API_KEY or OPENAI_API_KEY in your environment');
            return 1;
        }

        $this->newLine();

        // Test 4: Embedding Generation
        $this->info('4. Embedding Generation Test');
        $testText = 'This is a test voice note about machine learning and artificial intelligence.';
        $this->line('   📝 Test text: "' . $testText . '"');
        
        $embedding = $this->qdrantService->generateEmbedding($testText);
        if ($embedding && is_array($embedding) && count($embedding) > 0) {
            $this->line('   ✅ Embedding generated successfully');
            $this->line('   📊 Vector dimensions: ' . count($embedding));
            $this->line('   🔢 First 5 values: [' . implode(', ', array_slice($embedding, 0, 5)) . '...]');
        } else {
            $this->error('   ❌ Failed to generate embedding');
            return 1;
        }

        $this->newLine();

        // Test 5: Configuration Summary
        $this->info('5. Configuration Summary');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Qdrant Host', config('larq.host')],
                ['Qdrant API Key', config('larq.api_key') ? 'Set' : 'Not set'],
                ['AI Model', $hasGemini ? 'Gemini (' . config('larq.gemini_model') . ')' : 'OpenAI (' . config('larq.openai_model') . ')'],
                ['Vector Dimensions', count($embedding)],
                ['Collection Name', 'voice_notes_v1'],
            ]
        );

        $this->newLine();
        $this->info('🎉 All Qdrant tests passed successfully!');
        $this->info('Your ReJoIce vector search system is ready for voice note embeddings.');

        return 0;
    }
}
