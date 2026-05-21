<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Livewire\Admin\Meetings\ListMeetings;
use App\Livewire\Church\Songs\BrowseSongs;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaginationScrollTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_songs_listing_renders_results_container_with_correct_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(BrowseSongs::class)
            ->assertSeeHtml('id="song-results"');
    }

    #[Test]
    public function admin_meetings_listing_renders_results_container_with_correct_id(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ListMeetings::class)
            ->assertSeeHtml('id="admin-list-results"');
    }
}
