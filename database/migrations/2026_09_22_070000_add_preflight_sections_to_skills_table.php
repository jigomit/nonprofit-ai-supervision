<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            // Pulled out of the body at import so they can be shown before a
            // run rather than only handed to the model. 95 of the 102 skills
            // carry failure modes and 64 carry deliverables; both were sitting
            // unread inside the instructions.
            $table->json('failure_modes')->nullable()->after('supervision_note');
            $table->json('deliverables')->nullable()->after('failure_modes');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn(['failure_modes', 'deliverables']);
        });
    }
};
