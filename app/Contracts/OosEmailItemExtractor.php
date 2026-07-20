<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\OosEmailItemExtractionResult;

/**
 * Interface for extracting structured items from Order of Service (OoS) emails.
 *
 * Implementations should parse the subject and body of an OoS email to identify
 * components like songs, readings, and sermons.
 */
interface OosEmailItemExtractor
{
    /**
     * Extract structured items from the provided email subject and body.
     *
     * @param  string  $subject  The subject of the OoS email
     * @param  string  $body  The body text of the OoS email
     * @param  string  $receivedDate  The email receipt date in YYYY-MM-DD format, used to resolve relative and yearless dates
     * @return OosEmailItemExtractionResult The result containing extracted items or error details
     */
    public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult;
}
