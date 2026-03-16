<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordDefaultsTest extends TestCase
{
    #[Test]
    public function it_has_correct_password_defaults(): void
    {
        // Use a password that is definitely not in a breach database
        $this->assertTrue($this->validatePassword('K7#n2P$q9W!m5Z8v'));

        // Fails: too short
        $this->assertFalse($this->validatePassword('Short1!'));

        // Fails: no letters
        $this->assertFalse($this->validatePassword('12345678901!'));

        // Fails: no numbers
        $this->assertFalse($this->validatePassword('NoNumbersHere!'));

        // Fails: no symbols
        $this->assertFalse($this->validatePassword('NoSymbols1234'));
    }

    private function validatePassword(string $password): bool
    {
        return ! \Illuminate\Support\Facades\Validator::make(
            ['password' => $password],
            ['password' => Password::defaults()]
        )->fails();
    }
}
