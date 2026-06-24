<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\SermonAnalysis;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;

interface SermonAnalysisInterface
{
    /**
     * Analyze sermon transcript and extract metadata
     *
     * @param  string  $transcript  The sermon transcript text to analyze
     * @param  array<int, string>  $existingSeries  Existing sermon series for context
     * @param  string|null  $processingId  Processing run identifier used for log correlation
     * @return SermonAnalysis The analyzed sermon data
     *
     * @throws \Exception
     * @throws ErrorException
     * @throws TransporterException
     */
    public function analyzeSermon(string $transcript, array $existingSeries = [], ?string $processingId = null): SermonAnalysis;
}
