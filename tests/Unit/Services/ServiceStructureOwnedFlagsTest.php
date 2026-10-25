<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\Song\SongCatalogueNaming;
use App\Services\Song\SongTitleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureOwnedFlagsTest extends TestCase
{
    #[Test]
    #[DataProvider('obsoleteTypeFlags')]
    public function current_facts_replace_annotations_inherited_from_a_different_type(string $obsolete): void
    {
        $note = UntranscribedSpeechBeforeSection::note(0.0, 10.0);
        $independent = ['content_defect_hold', ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION, ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED];
        $section = new ServiceStructureSection(ServiceSectionType::Sermon, 'Sermon', 0.0, 600.0, 0.95, null, null, null, notes: [$note], reviewFlags: [$obsolete, ...$independent]);
        $validator = new ServiceStructureValidator(SongCatalogueNaming::using(SongTitleResolver::fromRows([])));
        $result = $validator->validate(ServiceStructure::fromSections([$section]), new ValidationContext(recordingDuration: 600.0, speechDuration: 600.0));

        $this->assertTrue($result->passed());
        $this->assertSame($independent, $result->structure->sections[0]->reviewFlags);
        $this->assertContains($note, $result->structure->sections[0]->notes);
    }

    /** @return array<string, array{string}> */
    public static function obsoleteTypeFlags(): array
    {
        return [
            'former benediction' => [ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT],
            'former interrupted talk' => [ServiceStructureValidator::FLAG_TALK_INTERRUPTED],
            'former talk fragment' => [ServiceStructureValidator::FLAG_TALK_FRAGMENT],
            'former song marker mismatch' => [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
        ];
    }
}
