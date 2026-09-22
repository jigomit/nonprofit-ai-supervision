<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            // A rejected run is never edited — it stays as it was decided, and
            // a second attempt is a new run that points back at it. The chain
            // is what tells a board the work was sent back and redone rather
            // than quietly rewritten.
            $table->foreignId('revised_from_id')
                ->nullable()
                ->after('task_schedule_id')
                ->constrained('task_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revised_from_id');
        });
    }
};
