<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        if (!DB::getSchemaBuilder()->hasTable('chunks')) return;
        DB::table('chunks')
          ->where('active_version', 'dictation')
          ->update([
            'edited_text'    => DB::raw("COALESCE(NULLIF(edited_text,''), ai_text)"),
            'active_version' => 'edited'
          ]);
    }
    public function down(): void {}
};
