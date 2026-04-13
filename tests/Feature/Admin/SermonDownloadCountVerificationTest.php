<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Sermons\EditSermon;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonDownloadCountVerificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_update_download_count_via_admin_interface(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sermon = Sermon::factory()->create([
            'download_count' => 10,
        ]);

        Livewire::actingAs($admin)
            ->test(EditSermon::class, ['sermon' => $sermon])
            ->assertSet('downloadCount', 10)
            ->set('downloadCount', 25)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(25, $sermon->fresh()->download_count);
    }

    #[Test]
    public function it_validates_download_count_is_not_negative(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sermon = Sermon::factory()->create([
            'download_count' => 10,
        ]);

        Livewire::actingAs($admin)
            ->test(EditSermon::class, ['sermon' => $sermon])
            ->set('downloadCount', -5)
            ->call('save')
            ->assertHasErrors(['downloadCount' => 'min']);

        $this->assertEquals(10, $sermon->fresh()->download_count);
    }
}
