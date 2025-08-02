<?php

/**
 * Database Tests - Comprehensive tests for SQLite database setup and model functionality
 * 
 * Requirements:
 * - Verify SQLite database connection and configuration
 * - Test UUID functionality for all models
 * - Test model relationships and foreign key constraints
 * - Test CRUD operations for all models
 * - Verify data integrity and cascading deletes
 * 
 * Flow:
 * - Database connection test -> Model creation tests -> Relationship tests -> CRUD operations
 */

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\Chunk;
use App\Models\Note;
use App\Models\User;
use App\Models\VectorEmbedding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_database_connection_works()
    {
        // Verify we can connect to SQLite database
        $this->assertDatabaseCount('users', 0);
        
        // Test basic database operations
        DB::statement('CREATE TEMPORARY TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
        DB::insert('INSERT INTO test_table (name) VALUES (?)', ['test']);
        
        $result = DB::select('SELECT name FROM test_table WHERE name = ?', ['test']);
        $this->assertCount(1, $result);
        $this->assertEquals('test', $result[0]->name);
    }

    /** @test */
    public function test_user_model_uses_uuid()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        // Verify UUID is generated
        $this->assertNotNull($user->id);
        $this->assertTrue(Str::isUuid($user->id));
        $this->assertIsString($user->id);
        
        // Verify user can be found by UUID
        $foundUser = User::find($user->id);
        $this->assertNotNull($foundUser);
        $this->assertEquals($user->email, $foundUser->email);
    }

    /** @test */
    public function test_note_model_functionality()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        // Test note creation with title
        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'My Test Note',
        ]);

        $this->assertNotNull($note->id);
        $this->assertTrue(Str::isUuid($note->id));
        $this->assertEquals('My Test Note', $note->title);
        $this->assertEquals($user->id, $note->user_id);

        // Test note creation without title (should auto-generate timestamp)
        $noteWithoutTitle = Note::create([
            'user_id' => $user->id,
        ]);

        $this->assertNotNull($noteWithoutTitle->title);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $noteWithoutTitle->title);

        // Test relationship
        $this->assertEquals($user->id, $note->user->id);
    }

    /** @test */
    public function test_audio_file_model_functionality()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Audio Note',
        ]);

        $audioFile = AudioFile::create([
            'note_id' => $note->id,
            'path' => 'storage/app/audio/' . $note->id . '/test.webm',
            'duration_seconds' => 120,
            'file_size_bytes' => 1024000,
            'mime_type' => 'audio/webm',
        ]);

        $this->assertNotNull($audioFile->id);
        $this->assertTrue(Str::isUuid($audioFile->id));
        $this->assertEquals($note->id, $audioFile->note_id);
        $this->assertEquals(120, $audioFile->duration_seconds);
        $this->assertEquals(1024000, $audioFile->file_size_bytes);

        // Test relationship
        $this->assertEquals($note->id, $audioFile->note->id);
        $this->assertTrue($note->audioFiles->contains($audioFile));
    }

    /** @test */
    public function test_chunk_model_functionality()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Chunk Note',
        ]);

        $audioFile = AudioFile::create([
            'note_id' => $note->id,
            'path' => 'storage/app/audio/' . $note->id . '/test.webm',
        ]);

        $chunk = Chunk::create([
            'note_id' => $note->id,
            'audio_id' => $audioFile->id,
            'dictation_text' => 'This is the raw dictation text',
            'ai_text' => 'This is the AI-refined text',
            'edited_text' => 'This is the user-edited text',
            'active_version' => 'ai',
            'chunk_order' => 1,
        ]);

        $this->assertNotNull($chunk->id);
        $this->assertTrue(Str::isUuid($chunk->id));
        $this->assertEquals($note->id, $chunk->note_id);
        $this->assertEquals($audioFile->id, $chunk->audio_id);
        $this->assertEquals('ai', $chunk->active_version);
        $this->assertEquals(1, $chunk->chunk_order);

        // Test active text accessor
        $this->assertEquals('This is the AI-refined text', $chunk->active_text);

        // Test relationships
        $this->assertEquals($note->id, $chunk->note->id);
        $this->assertEquals($audioFile->id, $chunk->audioFile->id);
        $this->assertTrue($note->chunks->contains($chunk));
        $this->assertTrue($audioFile->chunks->contains($chunk));
    }

    /** @test */
    public function test_vector_embedding_model_functionality()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Vector Note',
        ]);

        $audioFile = AudioFile::create([
            'note_id' => $note->id,
            'path' => 'storage/app/audio/' . $note->id . '/test.webm',
        ]);

        $chunk1 = Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'First chunk text',
            'active_version' => 'dictation',
            'chunk_order' => 1,
        ]);

        $chunk2 = Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'Second chunk text',
            'active_version' => 'dictation',
            'chunk_order' => 2,
        ]);

        $vectorEmbedding = VectorEmbedding::create([
            'note_id' => $note->id,
            'audio_id' => $audioFile->id,
            'chunk_ids' => [$chunk1->id, $chunk2->id],
            'source_text' => 'Combined text for embedding',
            'embedding_model' => 'models/embedding-001',
        ]);

        $this->assertNotNull($vectorEmbedding->id);
        $this->assertTrue(Str::isUuid($vectorEmbedding->id));
        $this->assertNotNull($vectorEmbedding->qdrant_point_id);
        $this->assertTrue(Str::isUuid($vectorEmbedding->qdrant_point_id));
        $this->assertEquals($note->id, $vectorEmbedding->note_id);
        $this->assertEquals($audioFile->id, $vectorEmbedding->audio_id);
        $this->assertIsArray($vectorEmbedding->chunk_ids);
        $this->assertCount(2, $vectorEmbedding->chunk_ids);
        $this->assertContains($chunk1->id, $vectorEmbedding->chunk_ids);
        $this->assertContains($chunk2->id, $vectorEmbedding->chunk_ids);
        $this->assertNotNull($vectorEmbedding->text_hash);

        // Test relationships
        $this->assertEquals($note->id, $vectorEmbedding->note->id);
        $this->assertEquals($audioFile->id, $vectorEmbedding->audioFile->id);
        $this->assertTrue($note->vectorEmbeddings->contains($vectorEmbedding));
    }

    /** @test */
    public function test_cascade_delete_functionality()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Delete Test Note',
        ]);

        $audioFile = AudioFile::create([
            'note_id' => $note->id,
            'path' => 'test.webm',
        ]);

        $chunk = Chunk::create([
            'note_id' => $note->id,
            'audio_id' => $audioFile->id,
            'dictation_text' => 'Test text',
            'active_version' => 'dictation',
            'chunk_order' => 1,
        ]);

        $vectorEmbedding = VectorEmbedding::create([
            'note_id' => $note->id,
            'audio_id' => $audioFile->id,
            'source_text' => 'Test embedding text',
        ]);

        // Verify records exist
        $this->assertDatabaseHas('notes', ['id' => $note->id]);
        $this->assertDatabaseHas('audio_files', ['id' => $audioFile->id]);
        $this->assertDatabaseHas('chunks', ['id' => $chunk->id]);
        $this->assertDatabaseHas('vector_embeddings', ['id' => $vectorEmbedding->id]);

        // Delete the note - should cascade delete related records
        $note->delete();

        // Verify cascade delete worked
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
        $this->assertDatabaseMissing('audio_files', ['id' => $audioFile->id]);
        $this->assertDatabaseMissing('chunks', ['id' => $chunk->id]);
        $this->assertDatabaseMissing('vector_embeddings', ['id' => $vectorEmbedding->id]);

        // User should still exist
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /** @test */
    public function test_chunk_active_text_functionality()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Text Version Test',
        ]);

        $chunk = Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'Raw dictation',
            'ai_text' => 'AI refined text',
            'edited_text' => 'User edited text',
            'active_version' => 'dictation',
            'chunk_order' => 1,
        ]);

        // Test different active versions
        $this->assertEquals('Raw dictation', $chunk->active_text);

        $chunk->update(['active_version' => 'ai']);
        $chunk->refresh();
        $this->assertEquals('AI refined text', $chunk->active_text);

        $chunk->update(['active_version' => 'edited']);
        $chunk->refresh();
        $this->assertEquals('User edited text', $chunk->active_text);
    }

    /** @test */
    public function test_vector_embedding_text_change_detection()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Change Detection Test',
        ]);

        $vectorEmbedding = VectorEmbedding::create([
            'note_id' => $note->id,
            'source_text' => 'This is a test text with exactly ten words total',
        ]);

        // Test small change (should not trigger re-embedding)
        $smallChange = 'This is a test text with exactly eleven words total';
        $this->assertFalse($vectorEmbedding->hasTextChangedSignificantly($smallChange));

        // Test large change (should trigger re-embedding)
        $largeChange = 'Completely different text that has many more words than the original text had';
        $this->assertTrue($vectorEmbedding->hasTextChangedSignificantly($largeChange));

        // Test empty source text
        $vectorEmbedding->update(['source_text' => '']);
        $this->assertTrue($vectorEmbedding->hasTextChangedSignificantly('Any new text'));
    }

    /** @test */
    public function test_database_indexes_and_constraints()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Index Test Note',
        ]);

        // Test that we can create multiple chunks with same note_id but different chunk_order
        $chunk1 = Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'First chunk',
            'active_version' => 'dictation',
            'chunk_order' => 1,
        ]);

        $chunk2 = Chunk::create([
            'note_id' => $note->id,
            'dictation_text' => 'Second chunk',
            'active_version' => 'dictation',
            'chunk_order' => 2,
        ]);

        // Test ordering
        $orderedChunks = $note->chunks()->ordered()->get();
        $this->assertEquals($chunk1->id, $orderedChunks->first()->id);
        $this->assertEquals($chunk2->id, $orderedChunks->last()->id);

        // Test that qdrant_point_id is unique
        $vectorEmbedding1 = VectorEmbedding::create([
            'note_id' => $note->id,
            'source_text' => 'First embedding',
        ]);

        $this->assertNotNull($vectorEmbedding1->qdrant_point_id);
        
        // Creating another vector embedding should get a different qdrant_point_id
        $vectorEmbedding2 = VectorEmbedding::create([
            'note_id' => $note->id,
            'source_text' => 'Second embedding',
        ]);

        $this->assertNotEquals($vectorEmbedding1->qdrant_point_id, $vectorEmbedding2->qdrant_point_id);
    }
}