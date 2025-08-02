<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Note;
use App\Models\AudioFile;
use App\Services\AudioService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AudioUploadTest extends TestCase
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
            'title' => 'Test Note for Audio Upload'
        ]);
        
        // Use fake storage for testing
        Storage::fake('local');
        
        // Ensure audio directory exists
        Storage::makeDirectory('audio');
    }

    public function test_audio_service_integration_with_models()
    {
        $audioService = new AudioService();
        
        // Create and store audio file
        $file = UploadedFile::fake()->create('integration_test.webm', 2048, 'audio/webm');
        $audioFile = $audioService->storeAudioFile($this->note->id, $file, [
            'duration_seconds' => 60
        ]);
        
        // Test model relationships
        $this->assertEquals($this->note->id, $audioFile->note_id);
        $this->assertEquals($this->note->id, $audioFile->note->id);
        $this->assertEquals($this->user->id, $audioFile->note->user_id);
        
        // Test note has audio files relationship
        $this->note->refresh();
        $this->assertEquals(1, $this->note->audioFiles()->count());
        $this->assertEquals($audioFile->id, $this->note->audioFiles()->first()->id);
    }

    public function test_audio_file_cascade_delete_with_note()
    {
        $audioService = new AudioService();
        
        // Create multiple audio files
        $files = [
            UploadedFile::fake()->create('audio1.webm', 1024, 'audio/webm'),
            UploadedFile::fake()->create('audio2.webm', 1024, 'audio/webm')
        ];
        
        $audioFiles = [];
        foreach ($files as $file) {
            $audioFiles[] = $audioService->storeAudioFile($this->note->id, $file);
        }
        
        // Verify files exist
        foreach ($audioFiles as $audioFile) {
            Storage::assertExists($audioFile->path);
            $this->assertDatabaseHas('audio_files', ['id' => $audioFile->id]);
        }
        
        // Delete the note (should cascade to audio files in database)
        $this->note->delete();
        
        // Verify database records are gone (cascade delete)
        foreach ($audioFiles as $audioFile) {
            $this->assertDatabaseMissing('audio_files', ['id' => $audioFile->id]);
        }
        
        // Note: In a real application, you'd want to use model events or 
        // explicit cleanup to also remove the physical files
    }

    public function test_audio_service_handles_multiple_files_per_note()
    {
        $audioService = new AudioService();
        
        // Store multiple audio files for same note
        $file1 = UploadedFile::fake()->create('recording1.webm', 1024, 'audio/webm');
        $file2 = UploadedFile::fake()->create('recording2.webm', 2048, 'audio/webm');
        $file3 = UploadedFile::fake()->create('recording3.webm', 1536, 'audio/webm');
        
        $audioFile1 = $audioService->storeAudioFile($this->note->id, $file1, ['duration_seconds' => 30]);
        $audioFile2 = $audioService->storeAudioFile($this->note->id, $file2, ['duration_seconds' => 45]);
        $audioFile3 = $audioService->storeAudioFile($this->note->id, $file3, ['duration_seconds' => 20]);
        
        // All files should be in the same note directory
        $notePath = "audio/{$this->note->id}";
        $this->assertTrue(Storage::exists($notePath));
        
        // Each file should have unique filename
        $this->assertNotEquals($audioFile1->path, $audioFile2->path);
        $this->assertNotEquals($audioFile2->path, $audioFile3->path);
        $this->assertNotEquals($audioFile1->path, $audioFile3->path);
        
        // All files should exist
        Storage::assertExists($audioFile1->path);
        Storage::assertExists($audioFile2->path);
        Storage::assertExists($audioFile3->path);
        
        // Database should have all records
        $this->assertEquals(3, AudioFile::where('note_id', $this->note->id)->count());
        
        // Test cleanup removes all files
        $deletedCount = $audioService->deleteAudioByNote($this->note->id);
        $this->assertEquals(3, $deletedCount);
        
        // Directory should be cleaned up
        Storage::assertMissing($notePath);
    }

    public function test_audio_storage_stats_accuracy()
    {
        $audioService = new AudioService();
        
        // Create files
        $files = [
            UploadedFile::fake()->create("test0.webm", 1024, 'audio/webm'),
            UploadedFile::fake()->create("test1.webm", 2048, 'audio/webm'),
            UploadedFile::fake()->create("test2.webm", 4096, 'audio/webm')
        ];
        
        $totalSize = 0;
        foreach ($files as $file) {
            $audioService->storeAudioFile($this->note->id, $file);
            $totalSize += $file->getSize(); // Use actual file size
        }
        
        // Get storage stats
        $stats = $audioService->getStorageStats();
        
        // Verify stats
        $this->assertEquals(3, $stats['total_files']);
        $this->assertEquals($totalSize, $stats['total_size_bytes']);
        $this->assertEquals(round($totalSize / (1024 * 1024), 2), $stats['total_size_mb']);
        $this->assertEquals(1, $stats['note_directories']); // One note directory
    }

    public function test_audio_file_validation_edge_cases()
    {
        $audioService = new AudioService();
        
        // Test valid size (should pass)
        $validFile = UploadedFile::fake()->create('valid_size.webm', 1024, 'audio/webm');
        
        // Should not throw exception
        $audioService->validateAudioFile($validFile);
        $this->assertTrue(true);
        
        // Test oversized file (should fail)
        // Note: Using a large fake file size for testing
        $maxSize = 50 * 1024 * 1024; // 50MB
        $oversizeFile = UploadedFile::fake()->create('oversize.webm', $maxSize + 1, 'audio/webm');
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File size exceeds maximum');
        
        $audioService->validateAudioFile($oversizeFile);
    }

    public function test_audio_metadata_consistency()
    {
        $audioService = new AudioService();
        
        // Create file with specific metadata
        $file = UploadedFile::fake()->create('metadata_test.webm', 3072, 'audio/webm');
        $duration = 120; // 2 minutes
        
        $audioFile = $audioService->storeAudioFile($this->note->id, $file, [
            'duration_seconds' => $duration
        ]);
        
        // Get metadata through service
        $metadata = $audioService->getAudioMetadata($audioFile->id);
        
        // Verify consistency between stored data and retrieved metadata
        $this->assertEquals($audioFile->id, $metadata['id']);
        $this->assertEquals($audioFile->note_id, $metadata['note_id']);
        $this->assertEquals($audioFile->path, $metadata['path']);
        $this->assertEquals($audioFile->duration_seconds, $metadata['duration_seconds']);
        $this->assertEquals($audioFile->file_size_bytes, $metadata['file_size_bytes']);
        $this->assertEquals($audioFile->mime_type, $metadata['mime_type']);
        $this->assertEquals($audioFile->created_at, $metadata['created_at']);
        
        // Verify actual file size matches stored size
        $this->assertEquals($metadata['file_size_bytes'], $metadata['actual_file_size_bytes']);
        $this->assertTrue($metadata['file_exists']);
    }

    public function test_audio_service_handles_missing_optional_metadata()
    {
        $audioService = new AudioService();
        
        // Store file without optional metadata
        $file = UploadedFile::fake()->create('no_metadata.webm', 1024, 'audio/webm');
        $audioFile = $audioService->storeAudioFile($this->note->id, $file);
        
        // Duration should be null when not provided
        $this->assertNull($audioFile->duration_seconds);
        
        // Other required fields should still be populated
        $this->assertNotNull($audioFile->id);
        $this->assertNotNull($audioFile->note_id);
        $this->assertNotNull($audioFile->path);
        $this->assertEquals($file->getSize(), $audioFile->file_size_bytes); // Use actual file size
        $this->assertEquals('audio/webm', $audioFile->mime_type);
        $this->assertNotNull($audioFile->created_at);
    }

    public function test_concurrent_audio_uploads_generate_unique_filenames()
    {
        $audioService = new AudioService();
        
        // Simulate concurrent uploads with identical file properties
        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = UploadedFile::fake()->create('same_name.webm', 1024, 'audio/webm');
        }
        
        $audioFiles = [];
        foreach ($files as $file) {
            $audioFiles[] = $audioService->storeAudioFile($this->note->id, $file);
        }
        
        // All files should have unique paths and IDs
        $paths = array_map(fn($af) => $af->path, $audioFiles);
        $ids = array_map(fn($af) => $af->id, $audioFiles);
        
        $this->assertEquals(5, count(array_unique($paths)));
        $this->assertEquals(5, count(array_unique($ids)));
        
        // All files should exist
        foreach ($audioFiles as $audioFile) {
            Storage::assertExists($audioFile->path);
        }
    }
}