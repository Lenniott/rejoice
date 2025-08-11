<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('audio_files')) return;
        Schema::table('audio_files', function (Blueprint $t) {
            if (!Schema::hasColumn('audio_files','status')) $t->string('status')->default('uploaded');
            if (!Schema::hasColumn('audio_files','transcribed_at')) $t->timestamp('transcribed_at')->nullable();
            if (!Schema::hasColumn('audio_files','deleted_at')) $t->timestamp('deleted_at')->nullable();
            $t->timestamp('transcript_verified_at')->nullable();
            $t->unsignedInteger('transcript_chunk_count')->default(0);
            $t->unsignedInteger('embedding_window_count')->default(0);
            $t->string('transcript_checksum', 64)->nullable(); // sha256 of concatenated chunks
            $t->string('delete_error')->nullable();
        });
    }
    public function down(): void {}
};
