<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRulingApplier;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\Song\SongCatalogueNaming;
use App\Services\Song\SongTitleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureDurationRulingTest extends TestCase
{
    #[Test]
    #[DataProvider('durationChoices')]
    public function validation_refreshes_duration_flags_after_an_answer(ServiceSectionType $type, float $originalDuration, ServiceSectionType $chosenType, float $chosenDuration, bool $micro, bool $macro): void
    {
        config(['media-processing.service_structure.min_section_seconds' => 15]);
        $flags = ['content_defect_hold'];
        if ($originalDuration < 15) {
            $flags[] = ServiceStructureValidator::FLAG_MICRO_SECTION;
        }
        if ($type === ServiceSectionType::Song && $originalDuration > 360) {
            $flags[] = ServiceStructureValidator::FLAG_MACRO_SECTION;
        }
        $original = new ServiceStructureSection($type, 'Item', 0.0, $originalDuration, 0.95, null, null, null, reviewFlags: $flags);
        $chosen = new ServiceStructureSection($chosenType, 'Item', 0.0, $chosenDuration, 0.95, null, null, null);
        $scope = ['type' => $type->value, 'start_time' => 0.0, 'end_time' => $originalDuration];
        $proposal = ['source_hash' => 'source-a', 'attempt_id' => 'attempt-1', 'structure' => ServiceStructure::fromSections([$original])->toArray(), 'disputes' => [['question_id' => 'duration-choice', 'written' => true, ...$scope]], 'degraded' => false];
        $ruling = ['ruling_key' => 'duration-choice', 'revision' => 1, 'source_hash' => 'source-a', 'scope' => $scope, 'kind' => 'correct', 'resolution' => ['sections' => [$chosen->toArray()]]];
        $corrected = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [$ruling]);
        $validator = new ServiceStructureValidator(SongCatalogueNaming::using(SongTitleResolver::fromRows([])));
        $result = $validator->validate(ServiceStructure::fromArray($corrected['structure']), new ValidationContext(recordingDuration: max($originalDuration, $chosenDuration), speechDuration: 0.0));

        $this->assertTrue($result->passed(), $result->failureSummary());
        $section = $result->structure->sections[0];
        $this->assertSame($chosenDuration, $section->duration());
        $this->assertSame($micro, in_array(ServiceStructureValidator::FLAG_MICRO_SECTION, $section->reviewFlags, true));
        $this->assertSame($macro, in_array(ServiceStructureValidator::FLAG_MACRO_SECTION, $section->reviewFlags, true));
        $this->assertContains('content_defect_hold', $section->reviewFlags);
    }

    /** @return array<string, array{ServiceSectionType, float, ServiceSectionType, float, bool, bool}> */
    public static function durationChoices(): array
    {
        return [
            'lengthen micro' => [ServiceSectionType::Other, 8.0, ServiceSectionType::Other, 18.72, false, false],
            'exact minimum' => [ServiceSectionType::Other, 8.0, ServiceSectionType::Other, 15.0, false, false],
            'new micro' => [ServiceSectionType::Other, 30.0, ServiceSectionType::Other, 10.0, true, false],
            'still micro' => [ServiceSectionType::Other, 8.0, ServiceSectionType::Other, 10.0, true, false],
            'shorten macro' => [ServiceSectionType::Song, 500.0, ServiceSectionType::Song, 180.0, false, false],
            'exact maximum' => [ServiceSectionType::Song, 500.0, ServiceSectionType::Song, 360.0, false, false],
            'new macro by type' => [ServiceSectionType::Other, 500.0, ServiceSectionType::Song, 500.0, false, true],
            'change macro type' => [ServiceSectionType::Song, 500.0, ServiceSectionType::Other, 500.0, false, false],
            'new macro' => [ServiceSectionType::Song, 180.0, ServiceSectionType::Song, 400.0, false, true],
            'still macro' => [ServiceSectionType::Song, 400.0, ServiceSectionType::Song, 500.0, false, true],
        ];
    }
}
