<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\Users\ListUsers;
use App\Models\ChurchService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchSecurityAndGroupingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_search_is_properly_grouped_and_escapes_wildcards(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create(['name' => 'Main Admin']);
        $this->actingAs($admin);

        // Matches search "John", but NOT admin
        User::factory()->create([
            'name' => 'John Search',
            'email' => 'john@example.com',
            'is_admin' => false,
        ]);

        // Matches search "John" AND IS admin
        User::factory()->create([
            'name' => 'John Admin',
            'email' => 'johnadmin@example.com',
            'is_admin' => true,
        ]);

        // 1. Verify search escaping (wildcards should be literal)
        User::factory()->create(['name' => 'User%WithWildcard', 'email' => 'wildcard@example.com']);

        Livewire::test(ListUsers::class)
            ->set('search', '%')
            ->assertSee('User%WithWildcard')
            ->assertDontSee('John Search');

        // 2. Verify search grouping (OR conditions shouldn't bypass other filters)
        Livewire::test(ListUsers::class)
            ->set('search', 'John')
            ->set('adminFilter', '1')
            ->assertDontSee('John Search')
            ->assertSee('John Admin');
    }

    #[Test]
    public function church_service_search_is_properly_grouped_and_escapes_wildcards(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $this->actingAs($admin);

        // Matches search "Match", but NOT needs_review
        ChurchService::factory()->create([
            'original_filename' => 'Match Ready',
            'needs_review' => false,
            'date' => '2026-01-01',
            'service' => SermonService::MORNING,
        ]);

        // Matches search "Match" AND needs_review
        ChurchService::factory()->create([
            'original_filename' => 'Match Review',
            'needs_review' => true,
            'date' => '2026-01-02',
            'service' => SermonService::EVENING,
        ]);

        // 1. Verify search escaping
        ChurchService::factory()->create([
            'original_filename' => 'File%With%Wildcard',
            'date' => '2026-01-03',
            'service' => SermonService::OTHER,
        ]);

        Livewire::test(ListChurchServices::class)
            ->set('search', '%')
            ->assertSee('File%With%Wildcard')
            ->assertDontSee('Match Ready');

        // 2. Verify search grouping
        Livewire::test(ListChurchServices::class)
            ->set('search', 'Match')
            ->set('needsReviewFilter', '1')
            ->assertDontSee('Match Ready')
            ->assertSee('Match Review');
    }
}
