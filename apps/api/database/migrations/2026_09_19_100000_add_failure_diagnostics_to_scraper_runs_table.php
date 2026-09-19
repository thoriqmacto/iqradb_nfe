<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the page actually contained when a step timed out.
 *
 * A locator failure against a legacy ASP.NET app is nearly impossible to fix
 * from the message alone, so the worker now reports a per-frame inventory of
 * roles and hyperlinks. It is sanitized in the worker before it is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraper_runs', function (Blueprint $table) {
            $table->json('failure_diagnostics')->nullable()->after('failed_action');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_runs', function (Blueprint $table) {
            $table->dropColumn('failure_diagnostics');
        });
    }
};
