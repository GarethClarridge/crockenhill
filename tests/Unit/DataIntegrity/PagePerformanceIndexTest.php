<?php

declare(strict_types=1);

namespace Tests\Unit\DataIntegrity;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PagePerformanceIndexTest extends TestCase
{
    #[Test]
    public function pages_table_has_expected_performance_indexes(): void
    {
        $this->assertTrue(
            Schema::hasIndex('pages', ['heading']),
            'Missing index on pages.heading'
        );

        $this->assertTrue(
            Schema::hasIndex('pages', ['admin']),
            'Missing index on pages.admin'
        );

        $this->assertTrue(
            Schema::hasIndex('pages', ['updated_at']),
            'Missing index on pages.updated_at'
        );
    }
}
