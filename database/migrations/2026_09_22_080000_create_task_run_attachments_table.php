<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_run_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_run_id')->constrained()->cascadeOnDelete();
            // Denormalised so a file can be scoped to its organization without
            // joining through the run it belongs to.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');

            // What actually reached the model. A file whose text could not be
            // read is kept and shown as unread rather than quietly ignored:
            // the person needs to know the model never saw their 990.
            $table->string('extraction')->default('pending');
            $table->longText('text')->nullable();
            $table->unsignedInteger('text_chars')->default(0);
            $table->boolean('truncated')->default(false);
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_run_attachments');
    }
};
