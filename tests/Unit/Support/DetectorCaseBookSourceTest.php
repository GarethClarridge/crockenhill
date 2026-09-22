<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\DetectorCaseBasis;
use App\Services\DetectorEvaluation\DetectorCaseBookSource;
use App\Support\DetectorCatalogue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DetectorCaseBookSourceTest extends TestCase
{
    /**
     * The binding that makes the case book complete for regression.
     *
     * Every plan case the catalogue names as a detector's regression fixture must
     * be discharged by a structured case or by a named aggregate. Without it a
     * fixture could sit in the catalogue as prose forever, cited as covered and
     * never scored.
     */
    public function test_every_catalogue_regression_reference_is_discharged(): void
    {
        $discharged = DetectorCaseBookSource::load()->dischargedReferences();

        foreach (DetectorCatalogue::all() as $entry) {
            foreach ($entry->regressionCases as $reference) {
                $this->assertContains(
                    $reference,
                    $discharged[$entry->id] ?? [],
                    "Catalogue entry [{$entry->id}] names regression case [{$reference}], which no case or aggregate in the case book discharges."
                );
            }
        }
    }

    /**
     * The reverse direction: a case may add evidence the catalogue never named,
     * but one that claims to discharge a reference must name one the catalogue
     * actually holds, or a typo would discharge nothing while reading as done.
     */
    public function test_every_case_reference_is_one_the_catalogue_holds(): void
    {
        $source = DetectorCaseBookSource::load();

        foreach ($source->cases() as $case) {
            if ($case->reference === null) {
                continue;
            }

            $this->assertContains(
                $case->reference,
                DetectorCatalogue::find($case->detectorId)?->regressionCases ?? [],
                "Case [{$case->caseId}] discharges [{$case->reference}], which catalogue entry [{$case->detectorId}] does not name."
            );
        }
    }

    /**
     * The song-loop census compared transcripts with catalogue lyrics, and the
     * plan says so. Those labels must never be counted as adjudicated truth.
     */
    public function test_song_loop_labels_are_not_counted_as_adjudicated(): void
    {
        $loops = array_filter(
            DetectorCaseBookSource::load()->cases(),
            static fn ($case): bool => $case->detectorId === 'song-looped-transcript',
        );

        $this->assertNotEmpty($loops);

        foreach ($loops as $case) {
            $this->assertFalse($case->basis->isAdjudicated(), "{$case->caseId} is scored as adjudicated truth.");
        }
    }

    public function test_a_fresh_decode_is_not_adjudicated_truth(): void
    {
        $this->assertFalse(DetectorCaseBasis::SourceRedecode->isAdjudicated());
        $this->assertTrue(DetectorCaseBasis::SourceReviewed->isAdjudicated());
    }

    public function test_the_hash_is_stable_and_content_bound(): void
    {
        $document = $this->document();
        $changed = $document;
        $changed['cases'][0]['truth'] = 'clean';

        $this->assertSame(
            DetectorCaseBookSource::fromArray($document)->hash(),
            DetectorCaseBookSource::fromArray($document)->hash(),
        );
        $this->assertNotSame(
            DetectorCaseBookSource::fromArray($document)->hash(),
            DetectorCaseBookSource::fromArray($changed)->hash(),
        );
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('invalidDocuments')]
    public function test_it_refuses_an_invalid_document(callable $mutate, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DetectorCaseBookSource::fromArray($mutate($this->document()));
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function invalidDocuments(): array
    {
        return [
            'wrong format' => [static fn (array $d): array => [...$d, 'format' => 'other'], 'format'],
            'unknown detector' => [static function (array $d): array {
                $d['cases'][0]['detector_id'] = 'no-such-detector';

                return $d;
            }, 'no-such-detector'],
            'unknown basis' => [static function (array $d): array {
                $d['cases'][0]['basis'] = 'hunch';

                return $d;
            }, 'basis'],
            'unknown truth' => [static function (array $d): array {
                $d['cases'][0]['truth'] = 'maybe';

                return $d;
            }, 'truth'],
            'no run' => [static function (array $d): array {
                unset($d['cases'][0]['subject']['run']);

                return $d;
            }, 'run'],
            'no evidence' => [static function (array $d): array {
                $d['cases'][0]['evidence'] = '';

                return $d;
            }, 'evidence'],
            'duplicate case id' => [static function (array $d): array {
                $d['cases'][] = $d['cases'][0];

                return $d;
            }, 'Duplicate'],
            'aggregate for an unknown detector' => [static function (array $d): array {
                $d['aggregates'][0]['detector_id'] = 'no-such-detector';

                return $d;
            }, 'no-such-detector'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'format' => DetectorCaseBookSource::Format,
            'version' => DetectorCaseBookSource::Version,
            'cases' => [[
                'case_id' => 'structure-macro-section/run-1007',
                'detector_id' => 'structure-macro-section',
                'reference' => 'run 1007',
                'subject' => ['run' => 1007, 'section' => null, 'sermon' => null],
                'truth' => 'defective',
                'basis' => 'source_redecode',
                'evidence' => 'plan §4.3a class table',
                'informed_fix' => true,
            ]],
            'aggregates' => [[
                'detector_id' => 'song-clip-audio-upsampled',
                'reference' => '245 of 464 clips',
                'register' => 'storage/scratch/tails-20260914-fingerprint.php',
                'reason' => 'Format census.',
            ]],
        ];
    }
}
