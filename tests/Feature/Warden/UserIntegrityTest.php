<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Livewire\Auth\Register;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_rejects_untrimmed_names_at_the_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only tested on MySQL.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_name_format_check');

        DB::table('users')->insert([
            'name' => '  Untrimmed Name  ',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
    }

    #[Test]
    public function it_rejects_empty_names_at_the_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only tested on MySQL.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_name_format_check');

        DB::table('users')->insert([
            'name' => '',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
    }

    #[Test]
    public function it_rejects_untrimmed_emails_at_the_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only tested on MySQL.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_email_format_check');

        DB::table('users')->insert([
            'name' => 'Test User',
            'email' => ' test@example.com ',
            'password' => 'password',
        ]);
    }

    #[Test]
    public function it_rejects_uppercase_emails_at_the_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only tested on MySQL.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_email_format_check');

        DB::table('users')->insert([
            'name' => 'Test User',
            'email' => 'TEST@example.com',
            'password' => 'password',
        ]);
    }

    #[Test]
    public function it_rejects_empty_emails_at_the_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only tested on MySQL.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_email_format_check');

        DB::table('users')->insert([
            'name' => 'Test User',
            'email' => '',
            'password' => 'password',
        ]);
    }

    #[Test]
    public function registration_component_enforces_max_length_constraints(): void
    {
        Livewire::test(Register::class)
            ->set('name', str_repeat('a', 256))
            ->set('email', str_repeat('a', 256).'@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasErrors(['name' => 'max', 'email' => 'max']);
    }

    #[Test]
    public function user_model_validation_rules_work_correctly(): void
    {
        $rules = User::validationRules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayHasKey('email', $rules);
        $this->assertContains('max:255', $rules['name']);
        $this->assertContains('max:255', $rules['email']);
    }
}
