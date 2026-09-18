<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable, structured description of "how to fetch report X from SCDB".
 *
 * `actions` is a validated JSON recipe — never executable source. `codegen_source`
 * keeps the original pasted Playwright Codegen text for audit only; it is
 * treated as inert text and is never executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_recipes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('start_url');
            $table->string('dataset_key');
            $table->boolean('enabled')->default(true);
            $table->string('expected_file_type')->default('csv');
            $table->string('expected_filename_pattern')->nullable();
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('actions');
            $table->json('import_config')->nullable();
            $table->text('codegen_source')->nullable();
            $table->timestamp('last_success_run_at')->nullable();
            $table->timestamp('last_failed_run_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'dataset_key']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_recipes');
    }
};
