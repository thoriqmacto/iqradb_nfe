<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staged source rows, preserved verbatim so an import can be audited and
 * replayed without re-downloading from SCDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status');
            $table->string('unique_key')->nullable();
            $table->json('payload');
            $table->json('normalized')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'status']);
            $table->index(['import_batch_id', 'unique_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
