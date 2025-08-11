<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('chunks')) return;
        Schema::table('chunks', function (Blueprint $t) {
            $t->uuid('batch_id')->nullable()->index();
        });
    }
    public function down(): void {}
};
