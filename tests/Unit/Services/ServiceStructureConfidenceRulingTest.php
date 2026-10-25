<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRulingApplier;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\Song\SongCatalogueNaming;
use App\Services\Song\SongTitleResolver;
use App\Support\ServiceSectionConfidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureConfidenceRulingTest extends TestCase
{
    #[Test]
    #[DataProvider('confidenceChoices')]
    public function validation_uses_the_chosen_confidence_without_losing_other_review_evidence(float $originalConfidence, float $chosenConfidence, bool $shouldHold): void
    {
        $note = UntranscribedSpeechBeforeSection::note(0.0, 10.0);
        $flags = ['unrelated_review', ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION];
        if ($originalConfidence < ServiceSectionConfidence::HIGH_THRESHOLD) {
            $flags[] = ServiceStructureValidator::FLAG_LOW_CONFIDENCE;
        }
        $original = new ServiceStructureSection(ServiceSectionType::Sermon, 'Sermon', 0.0, 600.0, $originalConfidence, null, null, null, notes: [$note], reviewFlags: $flags);
        $chosen = new ServiceStructureSection(ServiceSectionType::Sermon, 'Sermon', 0.0, 600.0, $chosenConfidence, null, null, null);
        $scope = ['type' => 'sermon', 'start_time' => 0.0, 'end_time' => 600.0];
        $proposal = [
            'source_hash' => 'source-a',
            'attempt_id' => 'attempt-1',
            'structure' => ServiceStructure::fromSections([$original])->toArray(),
            'disputes' => [['question_id' => 'confidence-choice', 'written' => true, ...$scope]],
            'degraded' => false,
        ];
        $ruling = ['ruling_key' => 'confidence-choice', 'revision' => 1, 'source_hash' => 'source-a', 'scope' => $scope, 'kind' => 'choose', 'resolution' => ['sections' => [$chosen->toArray()]]];
        $corrected = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [$ruling]);
        $validator = new ServiceStructureValidator(SongCatalogueNaming::using(SongTitleResolver::fromRows([])));
        $result = $validator->validate(ServiceStructure::fromArray($corrected['structure']), new ValidationContext(recordingDuration: 600.0, speechDuration: 600.0));

        $this->assertTrue($result->passed());
        $section = $result->structure->sections[0];
        $this->assertSame($chosenConfidence, $section->confidence);
        $this->assertSame($shouldHold, in_array(ServiceStructureValidator::FLAG_LOW_CONFIDENCE, $section->reviewFlags, true));
        $this->assertContains('unrelated_review', $section->reviewFlags);
        $this->assertContains(ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION, $section->reviewFlags);
        $this->assertContains($note, $section->notes);
    }

    /** @return array<string, array{float, float, bool}> */
    public static function confidenceChoices(): array
    {
        return [
            'choose high confidence' => [0.82, 0.96, false],
            'choose threshold confidence' => [0.82, 0.85, false],
            'choose another low confidence' => [0.82, 0.84, true],
            'choose low confidence from high' => [0.96, 0.82, true],
        ];
    }
}
