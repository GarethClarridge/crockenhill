<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScriptureForeignKeyIndexTest extends TestCase
{
    #[Test]
    public function it_has_an_index_on_scripture_passages_bible_id(): void
    {
        $this->assertTrue(
            Schema::hasIndex('scripture_passages', 'scripture_passages_bible_id_index'),
            'Index scripture_passages_bible_id_index does not exist on scripture_passages table.'
        );
    }

    #[Test]
    public function it_has_an_index_on_sermons_scripture_passage_id(): void
    {
        $this->assertTrue(
            Schema::hasIndex('sermons', 'sermons_scripture_passage_id_index') || Schema::hasIndex('sermons', 'sermons_scripture_passage_id_foreign'),
            'Index on scripture_passage_id does not exist on sermons table.'
        );
    }

    #[Test]
    public function it_has_an_index_on_speaker_profiles_preacher_id(): void
    {
        $this->assertTrue(
            Schema::hasIndex('speaker_profiles', 'speaker_profiles_preacher_id_index'),
            'Index speaker_profiles_preacher_id_index does not exist on speaker_profiles table.'
        );
    }

    #[Test]
    public function it_has_indexes_on_speaker_samples_foreign_keys(): void
    {
        $this->assertTrue(
            Schema::hasIndex('speaker_samples', 'speaker_samples_speaker_profile_id_index'),
            'Index speaker_samples_speaker_profile_id_index does not exist on speaker_samples table.'
        );
        $this->assertTrue(
            Schema::hasIndex('speaker_samples', 'speaker_samples_sermon_id_index'),
            'Index speaker_samples_sermon_id_index does not exist on speaker_samples table.'
        );
    }
}
