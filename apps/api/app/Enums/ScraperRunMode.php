<?php

namespace App\Enums;

/** How far down the pipeline a run is meant to go. */
enum ScraperRunMode: string
{
    /** Navigate the recipe but stop before downloading — a selector smoke test. */
    case TestNavigation = 'test_navigation';

    /** Navigate and capture the download, but do not parse or import. */
    case Download = 'download';

    /** Navigate, download, parse, stage, and import if an adapter exists. */
    case Import = 'import';

    public function capturesDownload(): bool
    {
        return $this !== self::TestNavigation;
    }

    public function importsCsv(): bool
    {
        return $this === self::Import;
    }
}
