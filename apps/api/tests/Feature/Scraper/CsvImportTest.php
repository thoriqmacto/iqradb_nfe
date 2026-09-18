<?php

namespace Tests\Feature\Scraper;

use App\Enums\ImportBatchStatus;
use App\Models\ImportRow;
use App\Models\ScraperRecipe;
use App\Models\ScraperRun;
use App\Models\User;
use App\Services\Import\CsvImporter;
use App\Services\Import\CsvReader;
use App\Services\Import\ImportAdapterRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeLoopAdapter;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private FakeLoopAdapter $adapter;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The real Loop Index model does not exist yet, so the target table is
        // created here for the duration of the test. See FakeLoopAdapter.
        Schema::create(FakeLoopAdapter::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('loop_no')->unique();
            $table->string('train');
            $table->string('status');
        });

        $this->adapter = new FakeLoopAdapter;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function importer(bool $withAdapter = true): CsvImporter
    {
        $registry = new ImportAdapterRegistry;

        if ($withAdapter) {
            $registry->register($this->adapter);
        }

        return new CsvImporter(new CsvReader, $registry);
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** @return array{0: ScraperRun, 1: ScraperRecipe} */
    private function makeRun(string $datasetKey = 'loop_index'): array
    {
        $user = User::factory()->create();
        $recipe = ScraperRecipe::factory()->create([
            'user_id' => $user->id,
            'dataset_key' => $datasetKey,
        ]);
        $run = ScraperRun::factory()->create([
            'user_id' => $user->id,
            'scraper_recipe_id' => $recipe->id,
            'downloaded_filename' => 'loop-index.csv',
        ]);

        return [$run, $recipe];
    }

    public function test_it_stages_and_upserts_rows(): void
    {
        [$run, $recipe] = $this->makeRun();
        $path = $this->csv("Loop No,Train,Status\nL-001,Train-8,Complete\nL-002,Train-9,Pending\n");

        $batch = $this->importer()->import($run, $recipe, $path, 'checksum-a');

        $this->assertSame(ImportBatchStatus::Completed, $batch->status);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, $batch->valid_rows);
        $this->assertSame(2, $batch->inserted);
        $this->assertSame(0, $batch->updated);
        $this->assertSame(2, DB::table(FakeLoopAdapter::TABLE)->count());
        $this->assertSame(2, $batch->rows()->count());
    }

    public function test_reimporting_the_same_data_is_idempotent(): void
    {
        [$run, $recipe] = $this->makeRun();
        $contents = "Loop No,Train,Status\nL-001,Train-8,Complete\n";

        $this->importer()->import($run, $recipe, $this->csv($contents), 'checksum-a');

        // Same data, different checksum, so the skip path does not short-circuit
        // what we are actually testing: the adapter's own idempotence.
        $second = $this->importer()->import($run, $recipe, $this->csv($contents), 'checksum-b');

        $this->assertSame(0, $second->inserted);
        $this->assertSame(0, $second->updated);
        $this->assertSame(1, $second->unchanged);
        $this->assertSame(1, DB::table(FakeLoopAdapter::TABLE)->count());
    }

    public function test_it_counts_updates_when_a_value_changes(): void
    {
        [$run, $recipe] = $this->makeRun();

        $this->importer()->import($run, $recipe, $this->csv("Loop No,Train,Status\nL-001,Train-8,Pending\n"), 'a');
        $second = $this->importer()->import($run, $recipe, $this->csv("Loop No,Train,Status\nL-001,Train-8,Complete\n"), 'b');

        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->updated);
        $this->assertSame('complete', DB::table(FakeLoopAdapter::TABLE)->first()->status);
    }

    public function test_invalid_rows_are_rejected_but_valid_ones_still_import(): void
    {
        [$run, $recipe] = $this->makeRun();
        $path = $this->csv(
            "Loop No,Train,Status\n".
            "L-001,Train-8,Complete\n".   // valid
            ",Train-9,Pending\n".         // missing identifier
            "L-003,Train-99,Pending\n"    // train out of range
        );

        $batch = $this->importer()->import($run, $recipe, $path, 'checksum-a');

        $this->assertSame(3, $batch->total_rows);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(2, $batch->invalid_rows);
        $this->assertSame(1, $batch->inserted);
        $this->assertSame(2, $batch->rejected);
        $this->assertSame(1, DB::table(FakeLoopAdapter::TABLE)->count());

        // The bad rows are still staged, with their reasons, for inspection.
        $invalid = ImportRow::where('status', ImportRow::STATUS_INVALID)->get();
        $this->assertCount(2, $invalid);
        $this->assertNotEmpty($invalid[0]->errors);
    }

    public function test_a_failing_adapter_rolls_back_its_chunk(): void
    {
        [$run, $recipe] = $this->makeRun();
        $this->adapter->explodeOnKey = 'L-002';

        $batch = $this->importer()->import(
            $run,
            $recipe,
            $this->csv("Loop No,Train,Status\nL-001,Train-8,Complete\nL-002,Train-9,Pending\n"),
            'checksum-a',
        );

        $this->assertSame(ImportBatchStatus::Failed, $batch->status);
        $this->assertStringContainsString('Simulated adapter failure', (string) $batch->error_message);

        // L-001 was inserted before the throw, in the same transaction — the
        // rollback must have taken it with it.
        $this->assertSame(0, DB::table(FakeLoopAdapter::TABLE)->count());

        // Staging survives, so the failure can be diagnosed without re-downloading.
        $this->assertSame(2, $batch->rows()->count());
    }

    public function test_a_missing_required_header_fails_the_batch(): void
    {
        [$run, $recipe] = $this->makeRun();
        $path = $this->csv("Loop No,Status\nL-001,Complete\n");

        $batch = $this->importer()->import($run, $recipe, $path, 'checksum-a');

        $this->assertSame(ImportBatchStatus::Failed, $batch->status);
        $this->assertStringContainsString('Train', (string) $batch->error_message);
        $this->assertSame(0, DB::table(FakeLoopAdapter::TABLE)->count());
    }

    public function test_a_dataset_without_an_adapter_stops_at_ready_for_mapping(): void
    {
        [$run, $recipe] = $this->makeRun('sat');
        $path = $this->csv("Tag,Result\nT-1,Pass\n");

        $batch = $this->importer(withAdapter: false)->import($run, $recipe, $path, 'checksum-a');

        $this->assertSame(ImportBatchStatus::ReadyForMapping, $batch->status);
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->valid_rows);
        // Rows are staged and previewable even with no target model.
        $this->assertSame(['Tag', 'Result'], $batch->headers);
        $this->assertSame(1, $batch->rows()->count());
    }

    public function test_an_identical_file_is_skipped_unless_forced(): void
    {
        [$run, $recipe] = $this->makeRun();
        $contents = "Loop No,Train,Status\nL-001,Train-8,Complete\n";

        $first = $this->importer()->import($run, $recipe, $this->csv($contents), 'same-checksum');
        $this->assertSame(ImportBatchStatus::Completed, $first->status);

        $second = $this->importer()->import($run, $recipe, $this->csv($contents), 'same-checksum');
        $this->assertSame(ImportBatchStatus::Skipped, $second->status);
        $this->assertStringContainsString('already imported', (string) $second->error_message);

        $forced = $this->importer()->import($run, $recipe, $this->csv($contents), 'same-checksum', force: true);
        $this->assertSame(ImportBatchStatus::Completed, $forced->status);
        $this->assertSame(1, $forced->unchanged);
    }

    public function test_it_records_source_metadata_for_audit(): void
    {
        [$run, $recipe] = $this->makeRun();
        $path = $this->csv("Loop No,Train,Status\nL-001,Train-8,Complete\n");

        $batch = $this->importer()->import($run, $recipe, $path, 'checksum-xyz');

        $this->assertSame('checksum-xyz', $batch->checksum);
        $this->assertSame('loop-index.csv', $batch->source_filename);
        $this->assertSame('loop_index', $batch->dataset_key);
        $this->assertSame($run->id, $batch->scraper_run_id);
        $this->assertSame($recipe->id, $batch->scraper_recipe_id);
        $this->assertSame(['Loop No', 'Train', 'Status'], $batch->headers);

        // The verbatim source row is preserved.
        $this->assertSame(
            ['Loop No' => 'L-001', 'Train' => 'Train-8', 'Status' => 'Complete'],
            $batch->rows()->first()->payload,
        );
    }
}
