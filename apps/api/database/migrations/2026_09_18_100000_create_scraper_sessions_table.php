<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's stored SCDB browser authentication state.
 *
 * `storage_state` holds a Playwright storageState JSON blob — cookies and
 * origin storage for an authenticated SCDB session. It is encrypted at rest
 * via the model's `encrypted:array` cast and is never serialised into an API
 * response, log line, or exception trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->text('storage_state');
            $table->string('status')->default('unknown');
            $table->timestamp('last_validated_at')->nullable();
            $table->string('last_validation_error')->nullable();
            $table->timestamps();

            // One stored session per user per SCDB host.
            $table->unique(['user_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_sessions');
    }
};
