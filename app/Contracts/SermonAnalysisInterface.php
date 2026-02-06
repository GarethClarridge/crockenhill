<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\SermonAnalysis;

interface SermonAnalysisInterface
{
    /**
     * Analyze sermon transcript and extract metadata
     *
     * @param  string  $transcript  The sermon transcript text to analyze
     * @param  array  $existingSeries  Existing sermon series for context
     */
    public function analyzeSermon(string $transcript, array $existingSeries = []): SermonAnalysis;
}
