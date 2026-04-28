<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Traits\EscapesLikeWildcards;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EscapesLikeWildcardsTest extends TestCase
{
    private object $traitInstance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->traitInstance = new class
        {
            use EscapesLikeWildcards;

            public function callEscapeLike(string $value): string
            {
                return $this->escapeLike($value);
            }
        };
    }

    #[Test]
    public function it_leaves_normal_strings_unchanged(): void
    {
        $this->assertSame('Normal String', $this->traitInstance->callEscapeLike('Normal String'));
    }

    #[Test]
    public function it_escapes_percent_character(): void
    {
        $this->assertSame('100\\%', $this->traitInstance->callEscapeLike('100%'));
    }

    #[Test]
    public function it_escapes_underscore_character(): void
    {
        $this->assertSame('some\\_name', $this->traitInstance->callEscapeLike('some_name'));
    }

    #[Test]
    public function it_escapes_backslash_character(): void
    {
        $this->assertSame('back\\\\slash', $this->traitInstance->callEscapeLike('back\\slash'));
    }

    #[Test]
    public function it_escapes_multiple_special_characters(): void
    {
        $this->assertSame('\\%\\_\\\\\\%\\_', $this->traitInstance->callEscapeLike('%_\\%_'));
    }
}
