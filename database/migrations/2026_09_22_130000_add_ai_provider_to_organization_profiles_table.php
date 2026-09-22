<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table) {
            $table->string('ai_provider')->nullable()->after('expert_gate_policy');
            $table->string('ai_model')->nullable()->after('ai_provider');

            // Encrypted at rest and never sent back to the browser. An
            // organization's key is its own; this application only ever spends
            // it on that organization's runs.
            $table->text('ai_api_key')->nullable()->after('ai_model');

            // Ollama and self-hosted gateways need an address rather than a key.
            $table->string('ai_base_url')->nullable()->after('ai_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_model', 'ai_api_key', 'ai_base_url']);
        });
    }
};
