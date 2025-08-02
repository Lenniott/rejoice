<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\AudioService;
use App\Models\AudioFile;
use App\Models\Note;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AudioServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AudioService $audioService;
    protected User $user;
    protected Note $note;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->audioService = new AudioService();
        
        // Create test user and note
        $this->user = User::factory()->create();
        $this->note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Test Note'
        ]);
        
        // Use fake storage for testing
        Storage::fake('local');
    }

    public function test_store_audio_file_creates_record_and_stores_file()
    {
        // Create a fake audio file
        $file = UploadedFile::fake()->create('test_audio.webm', 1024, 'audio/webm');
        
        // Store the audio file
        $audioFile = $this->audioService->storeAudioFile($this->note->id, $file, [
            'duration_seconds' => 30
        ]);
        
        // Assert database record was created
        $this->assertInstanceOf(AudioFile::class, $audioFile);
        $this->assertEquals($this->note->id, $audioFile->note_id);
        $this->assertEquals(30, $audioFile->duration_seconds);
        $this->assertEquals($file->getSize(), $audioFile->file_size_bytes); // Use actual file size
        $this->assertEquals('audio/webm', $audioFile->mime_type);
        
        // Assert file was stored
        Storage::assertExists($audioFile->path);
        
        // Assert path follows expected pattern
        $this->assertStringContainsString("audio/{$this->note->id}/", $audioFile->path);
        $this->assertStringEndsWith('.webm', $audioFile->path);
    }

    public function test_store_audio_file_creates_note_directory()
    {
        $file = UploadedFile::fake()->create('test_audio.webm', 1024, 'audio/webm');
        
        // Ensure directory doesn't exist initially
        $notePath = "audio/{$this->note->id}";
        Storage::assertMissing($notePath);
        
        // Store audio file
        $audioFile = $this->audioService->storeAudioFile($this->note->id, $file);
        
        // Assert directory was created
        $this->assertTrue(Storage::exists($notePath));
    }

    public function test_validate_audio_file_accepts_valid_formats()
    {
        $validFormats = [
            ['filename' => 'test.webm', 'mime' => 'audio/webm'],
            ['filename' => 'test.wav', 'mime' => 'audio/wav'],
            ['filename' => 'test.mp3', 'mime' => 'audio/mp3'],
            ['filename' => 'test.ogg', 'mime' => 'audio/ogg']
        ];
        
        foreach ($validFormats as $format) {
            $file = UploadedFile::fake()->create($format['filename'], 1024, $format['mime']);
            
            // Should not throw exception
            $this->audioService->validateAudioFile($file);
            $this->assertTrue(true); // If we get here, validation passed
        }
    }

    public function test_validate_audio_file_rejects_invalid_mime_type()
    {
        $file = UploadedFile::fake()->create('test.txt', 1024, 'text/plain');
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid file type');
        
        $this->audioService->validateAudioFile($file);
    }

    public function test_validate_audio_file_rejects_large_files()
    {
        // Create a file larger than 50MB (the default limit)
        $file = UploadedFile::fake()->create('large_audio.webm', 51 * 1024 * 1024, 'audio/webm');
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File size exceeds maximum');
        
        $this->audioService->validateAudioFile($file);
    }

    public function test_get_audio_path_returns_correct_path()
    {
        // Create audio file
        $file = UploadedFile::fake()->create('test_audio.webm', 1024, 'audio/webm');
        $audioFile = $this->audioService->storeAudioFile($this->note->id, $file);
        
        // Get path
        $path = $this->audioService->getAudioPath($audioFile->id);
        
        // Assert path is correct
        $this->assertStringContainsString($audioFile->path, $path);
        $this->assertTrue(file_exists($path));
    }

    public function test_get_audio_path_throws_exception_for_missing_file()
    {
        // Create audio record but don't store actual file
        $audioFile = AudioFile::create([
            'note_id' => $this->note->id,
            'path' => 'audio/nonexistent/file.webm',
            'mime_type' => 'audio/webm'
        ]);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Audio file not found on filesystem');
        
        $this->audioService->getAudioPath($audioFile->id);
    }

    public function test_delete_audio_file_removes_file_and_record()
    {
        // Create audio file
        $file = UploadedFile::fake()->create('test_audio.webm', 1024, 'audio/webm');
        $audioFile = $this->audioService->storeAudioFile($this->note->id, $file);
        
        // Assert file exists
        Storage::assertExists($audioFile->path);
        $this->assertDatabaseHas('audio_files', ['id' => $audioFile->id]);
        
        // Delete audio file
        $result = $this->audioService->deleteAudioFile($audioFile->id);
        
        // Assert deletion was successful
        $this->assertTrue($result);
        Storage::assertMissing($audioFile->path);
        $this->assertDatabaseMissing('audio_files', ['id' => $audioFile->id]);
    }

    public function test_delete_audio_by_note_removes_all_files()
    {
        // Create multiple audio files for the note
        $files = [
            UploadedFile::fake()->create('audio1.webm', 1024, 'audio/webm'),
            UploadedFile::fake()->create('audio2.webm', 1024, 'audio/webm'),
            UploadedFile::fake()->create('audio3.webm', 1024, 'audio/webm')
        ];
        
        $audioFiles = [];
        foreach ($files as $file) {
            $audioFiles[] = $this->audioService->storeAudioFile($this->note->id, $file);
        }
        
        // Assert all files exist
        foreach ($audioFiles as $audioFile) {
            Storage::assertExists($audioFile->path);
            $this->assertDatabaseHas('audio_files', ['id' => $audioFile->id]);
        }
        
        // Delete all audio files for the note
        $deletedCount = $this->audioService->deleteAudioByNote($this->note->id);
        
        // Assert all files were deleted
        $this->assertEquals(3, $deletedCount);
        foreach ($audioFiles as $audioFile) {
            Storage::assertMissing($audioFile->path);
            $this->assertDatabaseMissing('audio_files', ['id' => $audioFile->id]);
        }
        
        // Assert note directory was cleaned up
        $notePath = "audio/{$this->note->id}";
        Storage::assertMissing($notePath);
    }

    public function test_get_audio_metadata_returns_complete_information()
    {
        // Create audio file
        $file = UploadedFile::fake()->create('test_audio.webm', 2048, 'audio/webm');
        $audioFile = $this->audioService->storeAudioFile($this->note->id, $file, [
            'duration_seconds' => 45
        ]);
        
        // Get metadata
        $metadata = $this->audioService->getAudioMetadata($audioFile->id);
        
        // Assert metadata is complete
        $this->assertEquals($audioFile->id, $metadata['id']);
        $this->assertEquals($this->note->id, $metadata['note_id']);
        $this->assertEquals($audioFile->path, $metadata['path']);
        $this->assertEquals(45, $metadata['duration_seconds']);
        $this->assertEquals($file->getSize(), $metadata['file_size_bytes']); // Use actual file size
        $this->assertEquals($metadata['file_size_bytes'], $metadata['actual_file_size_bytes']); // Should match
        $this->assertEquals('audio/webm', $metadata['mime_type']);
        $this->assertTrue($metadata['file_exists']);
        $this->assertNotNull($metadata['created_at']);
    }

    public function test_get_storage_stats_returns_correct_information()
    {
        // Initially should be empty
        $stats = $this->audioService->getStorageStats();
        $this->assertEquals(0, $stats['total_files']);
        $this->assertEquals(0, $stats['total_size_bytes']);
        $this->assertEquals(0, $stats['note_directories']);
        
        // Create some audio files
        $file1 = UploadedFile::fake()->create('audio1.webm', 1024, 'audio/webm');
        $file2 = UploadedFile::fake()->create('audio2.webm', 2048, 'audio/webm');
        
        $this->audioService->storeAudioFile($this->note->id, $file1);
        $this->audioService->storeAudioFile($this->note->id, $file2);
        
        // Check stats again
        $stats = $this->audioService->getStorageStats();
        $this->assertEquals(2, $stats['total_files']);
        $expectedSize = $file1->getSize() + $file2->getSize(); // Use actual file sizes
        $this->assertEquals($expectedSize, $stats['total_size_bytes']);
        $this->assertEquals(1, $stats['note_directories']);
        $this->assertEquals(round($expectedSize / (1024 * 1024), 2), $stats['total_size_mb']);
    }

    public function test_store_audio_file_fails_with_invalid_note_id()
    {
        $file = UploadedFile::fake()->create('test_audio.webm', 1024, 'audio/webm');
        
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        
        $this->audioService->storeAudioFile('invalid-uuid', $file);
    }

    public function test_store_audio_file_cleans_up_on_failure()
    {
        // Create a file that will cause storage to fail (simulate disk full)
        $file = UploadedFile::fake()->create('test_audio.webm', 1024, 'audio/webm');
        
        // Mock Storage to fail on putFileAs
        Storage::shouldReceive('exists')->andReturn(false);
        Storage::shouldReceive('makeDirectory')->andReturn(true);
        Storage::shouldReceive('putFileAs')->andReturn(false); // Simulate failure
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to store audio file');
        
        $this->audioService->storeAudioFile($this->note->id, $file);
        
        // Ensure no database record was created
        $this->assertEquals(0, AudioFile::count());
    }
}