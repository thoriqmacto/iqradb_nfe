<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt at turning a downloaded CSV into IqraDB data.
 *
 * A batch always exists once a CSV is parsed, even when no target adapter is
 * registered for its dataset — in that case it settles at `ready_for_mapping`
 * with its rows staged, rather than guessing at a domain schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scraper_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scraper_recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dataset_key');
            $table->string('status');
            $table->string('source_filename');
            $table->string('checksum', 64);
            $table->json('headers');
            $table->unsignedInteger('mapping_version')->default(1);

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('rejected')->default(0);

            $table->text('error_message')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            // Drives the "this exact file was already imported" check.
            $table->index(['user_id', 'dataset_key', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
