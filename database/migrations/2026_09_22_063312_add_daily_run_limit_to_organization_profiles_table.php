<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table) {
            // Null means the application-wide default applies. A pilot
            // organization that genuinely works at volume gets its own number
            // rather than the host raising the cap for everyone.
            $table->unsignedInteger('daily_run_limit')->nullable()->after('ai_base_url');
        });
    }

    public function down(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table) {
            $table->dropColumn('daily_run_limit');
        });
    }
};
