<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditPasswordHashesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_passes_when_every_stored_hash_is_2y_bcrypt(): void
    {
        User::factory()->count(2)->create();

        $this->artisan('audit:password-hashes')
            ->expectsOutputToContain('All 2 stored password hashes are $2y$ bcrypt')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_on_a_legacy_hash_without_printing_hash_material(): void
    {
        User::factory()->create();
        $legacy = User::factory()->create();

        DB::table('users')->where('id', $legacy->id)->update([
            'password' => '$1$abcdefgh$legacymd5cryptmaterial',
        ]);

        $this->artisan('audit:password-hashes')
            ->doesntExpectOutputToContain('legacymd5cryptmaterial')
            ->expectsOutputToContain('1 of 2 stored hashes are not $2y$ bcrypt')
            ->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['non_bcrypt']);
        $this->assertSame(1, $report['counts']['other']);
        $this->assertSame(1, $report['counts']['bcrypt']);
    }

    #[Test]
    public function it_classifies_bcrypt_variants_and_argon_hashes_as_failures(): void
    {
        $variant = User::factory()->create();
        $argon = User::factory()->create();

        DB::table('users')->where('id', $variant->id)->update([
            'password' => '$2a$12$C6UzMDM.H6dfI/f/IKcEeO5xW1v6DdD1dOesqfKqLKMEyV1kqrGyW',
        ]);
        DB::table('users')->where('id', $argon->id)->update([
            'password' => '$argon2id$v=19$m=65536,t=4,p=1$c2FsdA',
        ]);

        $this->artisan('audit:password-hashes')->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['counts']['bcrypt_variant']);
        $this->assertSame(1, $report['counts']['argon2id']);
        $this->assertSame(2, $report['non_bcrypt']);
    }

    /** @return array{total: int, non_bcrypt: int, counts: array<string, int>} */
    private function jsonReport(): array
    {
        Artisan::call('audit:password-hashes --json');

        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);

        return $report;
    }
}
