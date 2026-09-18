<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One execution of a recipe.
 *
 * Artifact paths live here rather than in a separate table — a run has at most
 * one download, one screenshot and one trace, so a join would buy nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scraper_recipe_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Download metadata.
            $table->string('downloaded_filename')->nullable();
            $table->string('artifact_path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Failure diagnostics — sanitized, never secrets.
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('failed_step_index')->nullable();
            $table->json('failed_action')->nullable();
            $table->string('final_url')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->string('trace_path')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['scraper_recipe_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_runs');
    }
};
