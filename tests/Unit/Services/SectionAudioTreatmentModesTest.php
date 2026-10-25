<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Audio\PartLoudness;
use App\Services\Media\Audio\SectionAudioTreatment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SectionAudioTreatmentModesTest extends TestCase
{
    #[Test]
    public function it_uses_peak_and_range_to_attribute_modes_when_integrated_levels_are_nearly_equal(): void
    {
        $first = ['input_i' => -23.0, 'input_tp' => -8.89, 'input_lra' => 4.9, 'input_thresh' => -33.61, 'target_offset' => 0.0];
        $second = ['input_i' => -23.01, 'input_tp' => -0.3, 'input_lra' => 7.1, 'input_thresh' => -33.65, 'target_offset' => 0.0];
        $reports = [
            [...$second, 'input_i' => -23.0, 'normalization_type' => 'dynamic'],
            [...$first, 'input_i' => -23.01, 'normalization_type' => 'linear'],
        ];

        $this->assertSame(['linear', 'dynamic'], (new SectionAudioTreatment)->modesFromEncode(
            implode("\n", array_map(json_encode(...), $reports)),
            [new PartLoudness(60.0, $first), new PartLoudness(60.0, $second)],
        ));
    }

    #[Test]
    public function it_leaves_conflicting_indistinguishable_reports_unattributed(): void
    {
        $measured = ['input_i' => -23.0, 'input_tp' => -4.0, 'input_lra' => 5.0, 'input_thresh' => -33.0, 'target_offset' => 0.0];
        $stderr = json_encode([...$measured, 'normalization_type' => 'linear'])."\n".json_encode([...$measured, 'normalization_type' => 'dynamic']);

        $this->assertSame([null, null], (new SectionAudioTreatment)->modesFromEncode(
            $stderr,
            [new PartLoudness(60.0, $measured), new PartLoudness(60.0, $measured)],
        ));
    }

    #[Test]
    public function it_retains_legacy_reports_and_does_not_consume_them_for_gain_or_untreated_parts(): void
    {
        $measured = ['input_i' => -23.0, 'input_tp' => -4.0, 'input_lra' => 5.0, 'input_thresh' => -33.0, 'target_offset' => 0.0];

        $this->assertSame(['gain', null, 'linear', null], (new SectionAudioTreatment)->modesFromEncode(
            '{"input_i":"-23.01","normalization_type":"LINEAR"}',
            [new PartLoudness(60.0, $measured, plainGainDb: 2.0), PartLoudness::untreated(60.0, 'silence'), new PartLoudness(60.0, $measured), new PartLoudness(60.0, $measured)],
        ));
    }

    #[Test]
    public function it_ignores_malformed_nonfinite_and_nonmatching_reports(): void
    {
        $measured = ['input_i' => -23.0, 'input_tp' => -4.0, 'input_lra' => 5.0, 'input_thresh' => -33.0, 'target_offset' => 0.0];
        $reports = [
            ['input_i' => '1e999', 'normalization_type' => 'dynamic'],
            ['input_i' => -23, 'input_tp' => '-inf', 'normalization_type' => 'dynamic'],
            ['input_i' => -23, 'input_lra' => 'unknown', 'normalization_type' => 'dynamic'],
            ['input_i' => -23, 'normalization_type' => []],
            ['input_i' => -23, 'input_tp' => -1.0, 'normalization_type' => 'dynamic'],
            ['input_i' => -25, 'normalization_type' => 'linear'],
        ];

        $this->assertSame([null], (new SectionAudioTreatment)->modesFromEncode(
            implode("\n", array_map(json_encode(...), $reports)),
            [new PartLoudness(60.0, $measured)],
        ));
    }

    #[Test]
    public function it_accepts_duplicate_reports_when_their_modes_agree(): void
    {
        $measured = ['input_i' => -23.0, 'input_tp' => -4.0, 'input_lra' => 5.0, 'input_thresh' => -33.0, 'target_offset' => 0.0];
        $report = json_encode([...$measured, 'normalization_type' => 'linear']);

        $this->assertSame(['linear', 'linear'], (new SectionAudioTreatment)->modesFromEncode(
            $report."\n".$report,
            [new PartLoudness(60.0, $measured), new PartLoudness(60.0, $measured)],
        ));
    }
}
