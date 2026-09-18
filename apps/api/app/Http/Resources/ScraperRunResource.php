<?php

namespace App\Http\Resources;

use App\Models\ImportBatch;
use App\Models\ScraperRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScraperRun */
class ScraperRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $batch = $this->whenLoaded('importBatch');

        return [
            'id' => $this->uuid,
            'recipe' => [
                'id' => $this->whenLoaded('recipe', fn () => $this->recipe->uuid),
                'name' => $this->whenLoaded('recipe', fn () => $this->recipe->name),
                'dataset_key' => $this->whenLoaded('recipe', fn () => $this->recipe->dataset_key),
            ],
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_terminal' => $this->status->isTerminal(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'downloaded_filename' => $this->downloaded_filename,
            'checksum' => $this->checksum,
            'file_size' => $this->file_size,

            // Diagnostics — sanitized server-side before ever being stored.
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'failed_step_index' => $this->failed_step_index,
            'failed_action' => $this->failed_action,
            'final_url' => $this->final_url,
            'has_screenshot' => $this->screenshot_path !== null,
            'has_trace' => $this->trace_path !== null,

            'import' => $batch instanceof ImportBatch ? [
                'id' => $batch->uuid,
                'status' => $batch->status->value,
                'dataset_key' => $batch->dataset_key,
                'total_rows' => $batch->total_rows,
                'valid_rows' => $batch->valid_rows,
                'invalid_rows' => $batch->invalid_rows,
                'inserted' => $batch->inserted,
                'updated' => $batch->updated,
                'unchanged' => $batch->unchanged,
                'rejected' => $batch->rejected,
                'error_message' => $batch->error_message,
            ] : null,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
