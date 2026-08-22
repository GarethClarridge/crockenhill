<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Data\OpenLpParseResult;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use JsonException;
use ZipArchive;

class OpenLpServiceParser
{
    public function parse(UploadedFile $file): OpenLpParseResult
    {
        if (! $file->isValid()) {
            $this->throwValidation('The uploaded file is invalid.');
        }

        $zip = new ZipArchive;
        $zipOpenResult = $zip->open($file->getRealPath());

        if ($zipOpenResult !== true) {
            $this->throwValidation('The uploaded file must be a valid OpenLP .osz zip archive.');
        }

        $maxEntries = (int) config('service-tracking.upload.max_zip_entries', 100);
        if ($zip->numFiles > $maxEntries) {
            $zip->close();
            $this->throwValidation(
                "The uploaded archive contains too many entries ({$zip->numFiles}). Maximum allowed is {$maxEntries}."
            );
        }

        [$osjIndex, $osjEntryName] = $this->findOsjEntry($zip);

        if ($osjIndex === null || $osjEntryName === null) {
            $zip->close();
            $this->throwValidation('The uploaded archive does not contain an .osj service file.');
        }

        $stat = $zip->statIndex($osjIndex);

        if (is_array($stat)) {
            $maxBytes = (int) config('service-tracking.upload.max_osj_decompressed_bytes', 10 * 1024 * 1024);
            if ($stat['size'] > $maxBytes) {
                $zip->close();
                $this->throwValidation('The .osj service file exceeds the maximum allowed size when decompressed.');
            }

            $maxRatio = (float) config('service-tracking.upload.max_expansion_ratio', 1000);
            if ($stat['size'] > 0 && $stat['comp_size'] > 0 && ($stat['size'] / $stat['comp_size']) > $maxRatio) {
                $zip->close();
                $this->throwValidation('The .osj service file has a suspicious compression ratio and was rejected.');
            }
        }

        $osjContents = $zip->getFromIndex($osjIndex);
        $zip->close();

        if (! is_string($osjContents) || $osjContents === '') {
            $this->throwValidation('Unable to read the OpenLP .osj service file.');
        }

        try {
            $decoded = json_decode($osjContents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->throwValidation('The OpenLP .osj file contains invalid JSON.');
        }

        if (! is_array($decoded)) {
            $this->throwValidation('The OpenLP .osj file must contain a JSON array.');
        }

        $uploadIdentity = $this->identityFromFilename($file->getClientOriginalName());
        $embeddedIdentity = $this->identityFromFilename($osjEntryName);

        if ($uploadIdentity === null && $embeddedIdentity === null) {
            $this->throwValidation('Could not infer a service date from the uploaded filename or embedded .osj filename.');
        }

        $identity = $uploadIdentity ?? $embeddedIdentity;
        $parseMethod = $uploadIdentity !== null ? 'upload_filename' : 'embedded_filename';
        $confidence = $uploadIdentity !== null ? 1.0 : 0.8;

        $warnings = [];
        $filenameMismatch = false;

        if ($uploadIdentity === null) {
            $warnings[] = 'Used embedded .osj filename because upload filename did not include a parseable date.';
        }

        if ($uploadIdentity !== null && $embeddedIdentity !== null) {
            $dateDisagrees = $uploadIdentity['date'] !== $embeddedIdentity['date'];
            $serviceDisagrees = $embeddedIdentity['slot_known'] === true
                && $uploadIdentity['service'] !== $embeddedIdentity['service'];

            if ($dateDisagrees || $serviceDisagrees) {
                $filenameMismatch = true;
                $warnings[] = 'Upload filename and embedded .osj filename identities do not match.';
                $confidence -= 0.5;
            }
        }

        if ($identity['slot_known'] === false) {
            $warnings[] = 'Service slot was not detected in filename and defaulted to other.';
            $confidence -= 0.15;
        }

        $confidence = max(0.0, min(1.0, $confidence));
        $reviewThreshold = (float) config('service-tracking.confidence.review_below', 0.60);
        $needsReview = $confidence < $reviewThreshold;

        return new OpenLpParseResult(
            date: $identity['date'],
            service: $identity['service'],
            items: $this->extractItems($decoded),
            needsReview: $needsReview,
            importMetadata: [
                'confidence_score' => $confidence,
                'parse_method' => $parseMethod,
                'filename_mismatch' => $filenameMismatch,
                'warnings' => $warnings,
                'upload_filename' => $file->getClientOriginalName(),
                'embedded_filename' => $osjEntryName,
                'upload_identity' => $this->formatIdentityForMetadata($uploadIdentity),
                'embedded_identity' => $this->formatIdentityForMetadata($embeddedIdentity),
            ],
        );
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function findOsjEntry(ZipArchive $zip): array
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);

            if (! is_string($entryName)) {
                continue;
            }

            if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) === 'osj') {
                return [$index, $entryName];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<int, mixed>  $decoded
     * @return array<int, array{position:int,type:string,section_type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
     */
    private function extractItems(array $decoded): array
    {
        $items = [];
        $position = 1;

        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (array_key_exists('openlp_core', $entry)) {
                continue;
            }

            $serviceItem = $entry['serviceitem'] ?? null;
            if (! is_array($serviceItem)) {
                continue;
            }

            $header = $serviceItem['header'] ?? null;
            if (! is_array($header)) {
                $header = [];
            }

            $type = $this->normaliseType(
                $this->cleanString($this->valueAsString($header['plugin'] ?? null))
                ?? $this->cleanString($this->valueAsString($header['name'] ?? null))
                ?? 'custom'
            );

            $sourceTitle = $this->cleanString($this->valueAsString($header['title'] ?? null));
            $title = $sourceTitle ?? 'Untitled';
            $openLpSearchTitle = null;

            $metadata = [];
            $theme = $this->cleanString($this->valueAsString($header['theme'] ?? null));
            if ($theme !== null) {
                $metadata['theme'] = $theme;
            }

            if ($type === 'songs') {
                $songData = $header['data'] ?? null;

                if (is_array($songData)) {
                    $openLpSearchTitle = $this->cleanString($this->valueAsString($songData['title'] ?? null));

                    $authors = $this->cleanString($this->valueAsString($songData['authors'] ?? null));
                    if ($authors !== null) {
                        $metadata['authors'] = $authors;
                    }
                }
            }

            if ($type === 'bibles') {
                $footer = $header['footer'] ?? null;
                if (is_array($footer)) {
                    $footerReference = $this->cleanString($this->valueAsString($footer[0] ?? null));
                    if ($footerReference !== null) {
                        $title = $footerReference;
                    }
                }
            }

            $items[] = [
                'position' => $position,
                'type' => $type,
                'section_type' => $this->semanticTypeForStorageType($type, $title),
                'title' => $title,
                'source_title' => $sourceTitle,
                'openlp_search_title' => $openLpSearchTitle,
                'metadata' => $metadata !== [] ? $metadata : null,
            ];

            $position++;
        }

        return $items;
    }

    private function semanticTypeForStorageType(string $type, string $title): string
    {
        return match ($type) {
            'songs' => ServiceSectionType::Song->value,
            'bibles' => ServiceSectionType::BibleReading->value,
            default => ServiceSectionType::inferFromTitle($title)->value,
        };
    }

    /**
     * @return array{date: string, service: SermonService, slot_known: bool}|null
     */
    public function identityFromFilename(string $filename): ?array
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        if ($stem === '') {
            return null;
        }

        $date = $this->inferDateFromStem($stem);
        if ($date === null) {
            return null;
        }

        [$service, $slotKnown] = $this->inferService($stem);

        return [
            'date' => $date,
            'service' => $service,
            'slot_known' => $slotKnown,
        ];
    }

    /**
     * Patterns are tried in order and the order is load-bearing: the four-digit
     * year run-on must precede the two-digit one, or `02July2017` resolves as
     * `02 July 2020`.
     *
     * The separator-less grammars are the 2016-2017 back catalogue, which named
     * archives `20160103am`, `02July2017am` and `16July17am`. Their embedded
     * `.osj` names use a sixth grammar (`160103am`, a bare `ymd`) that is
     * deliberately *not* supported: it is too short to match without false
     * positives, and leaving it unparseable keeps `identityFromFilename()`
     * returning null for the embedded name, so no spurious `filename_mismatch`
     * is raised against an upload filename that already resolves cleanly.
     */
    private function inferDateFromStem(string $stem): ?string
    {
        $patterns = [
            '/(\d{4}-\d{2}-\d{2})/',
            '/\b(\d{1,2}-[A-Za-z]{3,9}-\d{2,4})\b/',
            '/\b(\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4})\b/',
            '/(?<!\d)(\d{8})(?!\d)/',
            '/(?<!\d)(\d{1,2})([A-Za-z]{3,9})(\d{4})(?!\d)/',
            '/(?<!\d)(\d{1,2})([A-Za-z]{3,9})(\d{2})(?!\d)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $stem, $matches) !== 1) {
                continue;
            }

            $rawDate = isset($matches[3])
                ? "{$matches[1]} {$matches[2]} {$matches[3]}"
                : $matches[1];

            $date = $this->normaliseDate($rawDate);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @return array{0: SermonService, 1: bool}
     */
    private function inferService(string $value): array
    {
        $normalised = strtoupper((string) preg_replace('/[^a-zA-Z]+/', ' ', $value));

        if (preg_match('/\b(AM|MORNING)\b/', $normalised) === 1) {
            return [SermonService::Morning, true];
        }

        if (preg_match('/\b(PM|EVENING)\b/', $normalised) === 1) {
            return [SermonService::Evening, true];
        }

        return [SermonService::Other, false];
    }

    private function normaliseDate(string $date): ?string
    {
        /**
         * Two-digit-year formats precede their four-digit counterparts. PHP's
         * `Y` will happily read `17` as the year 17, so `16 July 17` must meet
         * `j F y` first; a four-digit year reaching `j F y` is rejected by the
         * trailing-data warning below, which is what makes the order safe.
         */
        $formats = [
            'Y-m-d',
            'j-M-y',
            'd-M-y',
            'j-M-Y',
            'd-M-Y',
            'j F y',
            'd F y',
            'j M y',
            'd M y',
            'j F Y',
            'd F Y',
            'j M Y',
            'd M Y',
            'Ymd',
        ];

        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $date);
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed === false) {
                continue;
            }

            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return $parsed->format('Y-m-d');
        }

        return null;
    }

    private function normaliseType(string $type): string
    {
        $normalised = strtolower(trim($type));

        return match ($normalised) {
            'song', 'songs' => 'songs',
            'bible', 'bibles' => 'bibles',
            'presentation', 'presentations' => 'presentations',
            'custom' => 'custom',
            '' => 'custom',
            default => $normalised,
        };
    }

    private function cleanString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/', ' ', $value));

        return $collapsed === '' ? null : $collapsed;
    }

    private function valueAsString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array{date: string, service: SermonService, slot_known: bool}|null  $identity
     * @return array{date: string, service: string, slot_known: bool}|null
     */
    private function formatIdentityForMetadata(?array $identity): ?array
    {
        if ($identity === null) {
            return null;
        }

        return [
            'date' => $identity['date'],
            'service' => $identity['service']->value,
            'slot_known' => $identity['slot_known'],
        ];
    }

    private function throwValidation(string $message): never
    {
        throw ValidationException::withMessages([
            'file' => $message,
        ]);
    }
}
