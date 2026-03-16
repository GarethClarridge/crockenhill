<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_index_on_created_at_column(): void
    {
        $this->assertTrue(
            Schema::hasIndex('users', 'users_created_at_index'),
            'The users table should have an index on the created_at column.'
        );
    }
}
