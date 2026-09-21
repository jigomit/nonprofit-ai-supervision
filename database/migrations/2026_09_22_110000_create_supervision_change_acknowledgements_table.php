<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A supervision change is a fact about the library and is shared by
        // every organization, but being told about it is not. Acknowledgement
        // has to be per-tenant or one organization clearing an alert would
        // silently clear it for everyone else.
        Schema::create('supervision_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervision_change_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acknowledged_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            // Named explicitly: the generated name would exceed MySQL's
            // 64-character identifier limit.
            $table->unique(['team_id', 'supervision_change_id'], 'team_supervision_change_unique');
        });

        Schema::table('supervision_changes', function (Blueprint $table) {
            $table->dropIndex(['acknowledged_at']);
            $table->dropColumn('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('supervision_changes', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable();
            $table->index('acknowledged_at');
        });

        Schema::dropIfExists('supervision_acknowledgements');
    }
};
