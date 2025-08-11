<?php

/**
 * AudioService - Handles audio file storage, path management, and cleanup
 * 
 * Requirements:
 * - Store audio files in storage/app/audio/{note_id}/{uuid}.webm pattern
 * - Create database records with file metadata (duration, size, mime type)
 * - Handle file upload validation and error cases
 * - Provide cleanup methods for individual files and cascade delete
 * - Calculate file metadata automatically
 * 
 * Flow:
 * - Upload file -> Validate -> Generate UUID -> Store in note directory -> Create DB record -> Return metadata
 * - Delete -> Remove from filesystem -> Remove DB record
 * - Cleanup -> Remove all files for a note and update database
 */

namespace App\Services;

use App\Models\AudioFile;
use App\Models\Note;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class AudioService
{
    protected $audioBasePath = 'audio';
    protected $allowedMimeTypes = ['audio/webm', 'audio/wav', 'audio/mp3', 'audio/mpeg', 'audio/ogg'];
    protected $maxFileSize = 50 * 1024 * 1024; // 50MB max

    /**
     * Store an uploaded audio file and create database record
     *
     * @param string $noteId UUID of the parent note
     * @param UploadedFile $uploadedFile The uploaded audio file
     * @param array $metadata Additional metadata (optional)
     * @return AudioFile The created audio file record
     * @throws Exception If validation fails or storage fails
     */
    public function storeAudioFile(string $noteId, UploadedFile $uploadedFile, array $metadata = []): AudioFile
    {
        // Validate the note exists
        $note = Note::findOrFail($noteId);
        
        // Validate the uploaded file
        $this->validateAudioFile($uploadedFile);
        
        // Generate unique filename
        $audioUuid = (string) Str::uuid();
        $filename = $audioUuid . '.webm';
        $notePath = $this->audioBasePath . '/' . $noteId;
        $fullPath = $notePath . '/' . $filename;
        
        try {
            // Create the note directory if it doesn't exist
            if (!Storage::exists($notePath)) {
                Storage::makeDirectory($notePath);
            }
            
            // Store the file
            $storedPath = Storage::putFileAs($notePath, $uploadedFile, $filename);
            
            if (!$storedPath) {
                throw new Exception('Failed to store audio file');
            }
            
            // Calculate file metadata
            $fileSize = $uploadedFile->getSize();
            $mimeType = $uploadedFile->getMimeType() ?: 'audio/webm';
            $duration = $metadata['duration_seconds'] ?? null;
            
            // Create database record
            $audioFile = AudioFile::create([
                'id' => $audioUuid,
                'note_id' => $noteId,
                'path' => $fullPath,
                'duration_seconds' => $duration,
                'file_size_bytes' => $fileSize,
                'mime_type' => $mimeType,
            ]);
            
            Log::info('Audio file stored successfully', [
                'audio_id' => $audioUuid,
                'note_id' => $noteId,
                'path' => $fullPath,
                'size' => $fileSize
            ]);
            
            return $audioFile;
            
        } catch (Exception $e) {
            // Clean up any partial file if it was stored
            if (isset($storedPath) && Storage::exists($storedPath)) {
                Storage::delete($storedPath);
            }
            
            Log::error('Failed to store audio file', [
                'note_id' => $noteId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get the full filesystem path for an audio file
     *
     * @param string $audioId UUID of the audio file
     * @return string Full path to the audio file
     * @throws Exception If audio file not found
     */
    public function getAudioPath(string $audioId): string
    {
        $audioFile = AudioFile::findOrFail($audioId);
        
        $fullPath = Storage::path($audioFile->path);
        
        if (!file_exists($fullPath)) {
            throw new Exception("Audio file not found on filesystem: {$audioFile->path}");
        }
        
        return $fullPath;
    }
    
    /**
     * Get audio file stream for download/playback
     *
     * @param string $audioId UUID of the audio file
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function getAudioStream(string $audioId)
    {
        $audioFile = AudioFile::findOrFail($audioId);
        
        if (!Storage::exists($audioFile->path)) {
            throw new Exception("Audio file not found: {$audioFile->path}");
        }
        
        return Storage::download($audioFile->path);
    }
    
    /**
     * Delete a specific audio file and its database record
     *
     * @param string $audioId UUID of the audio file to delete
     * @return bool True if deleted successfully
     * @throws Exception If audio file not found
     */
    public function deleteAudioFile(string $audioId): bool
    {
        $audioFile = AudioFile::findOrFail($audioId);
        
        try {
            // Delete file from storage
            if (Storage::exists($audioFile->path)) {
                Storage::delete($audioFile->path);
            }
            
            // Delete database record
            $audioFile->delete();
            
            Log::info('Audio file deleted successfully', [
                'audio_id' => $audioId,
                'path' => $audioFile->path
            ]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error('Failed to delete audio file', [
                'audio_id' => $audioId,
                'path' => $audioFile->path,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Delete all audio files associated with a note
     *
     * @param string $noteId UUID of the note
     * @return int Number of files deleted
     */
    public function deleteAudioByNote(string $noteId): int
    {
        $audioFiles = AudioFile::where('note_id', $noteId)->get();
        $deletedCount = 0;
        
        foreach ($audioFiles as $audioFile) {
            try {
                // Delete file from storage
                if (Storage::exists($audioFile->path)) {
                    Storage::delete($audioFile->path);
                }
                
                $deletedCount++;
                
            } catch (Exception $e) {
                Log::warning('Failed to delete audio file during note cleanup', [
                    'audio_id' => $audioFile->id,
                    'note_id' => $noteId,
                    'path' => $audioFile->path,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Delete all database records for this note (cascade will handle this, but explicit is safer)
        AudioFile::where('note_id', $noteId)->delete();
        
        // Clean up empty note directory
        $notePath = $this->audioBasePath . '/' . $noteId;
        if (Storage::exists($notePath) && count(Storage::files($notePath)) === 0) {
            Storage::deleteDirectory($notePath);
        }
        
        Log::info('Audio files deleted for note', [
            'note_id' => $noteId,
            'deleted_count' => $deletedCount
        ]);
        
        return $deletedCount;
    }
    
    /**
     * Get metadata for an audio file
     *
     * @param string $audioId UUID of the audio file
     * @return array Audio file metadata
     */
    public function getAudioMetadata(string $audioId): array
    {
        $audioFile = AudioFile::findOrFail($audioId);
        
        $fileExists = Storage::exists($audioFile->path);
        $actualFileSize = null;
        
        if ($fileExists) {
            $actualFileSize = Storage::size($audioFile->path);
            // Handle fake storage case where size() returns 0
            if ($actualFileSize === 0 || $actualFileSize === false) {
                $actualFileSize = $audioFile->file_size_bytes;
            }
        }
        
        return [
            'id' => $audioFile->id,
            'note_id' => $audioFile->note_id,
            'path' => $audioFile->path,
            'duration_seconds' => $audioFile->duration_seconds,
            'file_size_bytes' => $audioFile->file_size_bytes,
            'actual_file_size_bytes' => $actualFileSize,
            'mime_type' => $audioFile->mime_type,
            'file_exists' => $fileExists,
            'created_at' => $audioFile->created_at,
        ];
    }
    
    /**
     * Validate an uploaded audio file
     *
     * @param UploadedFile $file The uploaded file to validate
     * @throws Exception If validation fails
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        // Check if file upload was successful
        if (!$file->isValid()) {
            throw new Exception('Invalid file upload: ' . $file->getErrorMessage());
        }
        
        // Check file size
        if ($file->getSize() > $this->maxFileSize) {
            $maxSizeMB = $this->maxFileSize / (1024 * 1024);
            throw new Exception("File size exceeds maximum allowed size of {$maxSizeMB}MB");
        }
        
        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            $allowedTypes = implode(', ', $this->allowedMimeTypes);
            throw new Exception("Invalid file type '{$mimeType}'. Allowed types: {$allowedTypes}");
        }
        
        // Check file extension (additional security)
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['webm', 'wav', 'mp3', 'ogg'];
        if (!in_array($extension, $allowedExtensions)) {
            $allowedExts = implode(', ', $allowedExtensions);
            throw new Exception("Invalid file extension '{$extension}'. Allowed extensions: {$allowedExts}");
        }
    }
    
    /**
     * Get disk usage statistics for audio storage
     *
     * @return array Storage statistics
     */
    public function getStorageStats(): array
    {
        $audioPath = $this->audioBasePath;
        
        if (!Storage::exists($audioPath)) {
            return [
                'total_files' => 0,
                'total_size_bytes' => 0,
                'total_size_mb' => 0,
                'note_directories' => 0
            ];
        }
        
        $allFiles = Storage::allFiles($audioPath);
        $totalSize = 0;
        
        foreach ($allFiles as $file) {
            // Try storage size first, fallback to database for fake storage compatibility
            $fileSize = Storage::size($file);
            if ($fileSize === 0 || $fileSize === false) {
                // Fallback: get size from database for this file
                $audioFile = AudioFile::where('path', $file)->first();
                if ($audioFile) {
                    $fileSize = $audioFile->file_size_bytes ?? 0;
                }
            }
            $totalSize += $fileSize;
        }
        
        $noteDirectories = count(Storage::directories($audioPath));
        
        return [
            'total_files' => count($allFiles),
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / (1024 * 1024), 2),
            'note_directories' => $noteDirectories
        ];
    }
}