<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPreacherIndexTest extends TestCase
{
    #[Test]
    public function it_has_the_preacher_id_and_date_composite_index(): void
    {
        $this->assertTrue(
            Schema::hasIndex('sermons', 'sermons_preacher_id_date_index'),
            'The sermons table should have a composite index on [preacher_id, date].'
        );
    }
}
