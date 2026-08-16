<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\ChurchService\HistoricItemGroundTruth;
use App\Services\Email\HistoricEmailContentCalibration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HistoricEmailContentCalibrationTest extends TestCase
{
    #[Test]
    public function it_fits_and_scores_only_against_corroborated_content_verdicts(): void
    {
        $groundTruth = $this->groundTruth([
            ['key' => '2023-01-01 morning', 'verdicts' => ['song_membership' => 'match', 'song_count' => 'match']],
            ['key' => '2023-01-08 morning', 'verdicts' => ['song_membership' => 'mismatch', 'song_count' => 'match']],
            ['key' => '2023-01-15 morning', 'verdicts' => ['song_membership' => 'circular', 'song_count' => 'not_corroborated']],
        ]);
        $report = $this->report([
            ['date' => '2023-01-01', 'service' => 'morning', 'confidence' => 0.95, 'identity_correct' => false],
            ['date' => '2023-01-08', 'service' => 'morning', 'confidence' => 0.95, 'identity_correct' => true],
            ['date' => '2023-01-15', 'service' => 'morning', 'confidence' => 1.0, 'identity_correct' => true],
        ]);

        $artifact = (new HistoricEmailContentCalibration)->calibrate($report, $groundTruth);

        $this->assertSame('model_confidence_baseline', $artifact['policy']['candidate']);
        $this->assertFalse($artifact['policy']['live_gate_changed']);
        $this->assertSame(2, $artifact['observations']['labelled']);
        $this->assertSame(2, $artifact['observations']['training'] + $artifact['observations']['holdout']);
        $this->assertSame(
            1,
            $artifact['training']['correct'] + $artifact['holdout']['correct'],
            'The identity_correct fields in the archive report are deliberately ignored.',
        );
    }

    #[Test]
    public function it_excludes_unlabelled_identities_and_refuses_a_threshold_without_training_precision(): void
    {
        $groundTruth = $this->groundTruth([
            ['key' => '2023-01-01 morning', 'verdicts' => ['song_membership' => 'mismatch']],
            ['key' => '2023-01-08 morning', 'verdicts' => ['song_membership' => 'indeterminate']],
        ]);
        $report = $this->report([
            ['date' => '2023-01-01', 'service' => 'morning', 'confidence' => 1.0],
            ['date' => '2023-01-08', 'service' => 'morning', 'confidence' => 1.0],
        ]);

        $artifact = (new HistoricEmailContentCalibration)->calibrate($report, $groundTruth);

        $this->assertSame(1, $artifact['observations']['labelled']);
        $this->assertNull($artifact['fit']['threshold']);
        $this->assertSame('No training threshold meets the minimum precision.', $artifact['fit']['reason']);
    }

    /** @param list<array{key:string,verdicts:array<string,string>}> $identities
     * @return array<string,mixed>
     */
    private function groundTruth(array $identities): array
    {
        return [
            'format' => HistoricItemGroundTruth::Format,
            'identities' => $identities,
        ];
    }

    /** @param list<array{date:string,service:string,confidence:float,identity_correct?:bool}> $plans
     * @return array<string,mixed>
     */
    private function report(array $plans): array
    {
        return ['entries' => [['content_scope' => 'full', 'plans' => $plans]]];
    }
}
