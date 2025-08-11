<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('chunks')) {
            Schema::table('chunks', function (Blueprint $t) {
                // Drop foreign key constraints and indexes first
                if (Schema::hasColumn('chunks','audio_id')) {
                    $t->dropForeign(['audio_id']);
                    $t->dropIndex('idx_chunks_audio_id');
                    $t->dropColumn('audio_id');
                }
                if (Schema::hasColumn('chunks','dictation_text')) {
                    $t->dropColumn('dictation_text');
                }
            });
        }
        if (Schema::hasTable('vector_embeddings')) {
            Schema::table('vector_embeddings', function (Blueprint $t) {
                if (Schema::hasColumn('vector_embeddings','audio_id')) {
                    $t->dropForeign(['audio_id']);
                    $t->dropIndex('idx_vector_embeddings_audio_id');
                    $t->dropColumn('audio_id');
                }
            });
        }
    }
    public function down(): void {}
};
