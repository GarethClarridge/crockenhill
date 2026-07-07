<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\Pages\EditPage;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageValidationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_validates_description_max_length_from_model(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($admin);
        $page = Page::factory()->create();

        Livewire::test(EditPage::class, ['page' => $page])
            ->set('form.description', str_repeat('a', 156))
            ->call('save')
            ->assertHasErrors(['form.description' => 'max']);

        Livewire::test(EditPage::class, ['page' => $page])
            ->set('form.description', str_repeat('a', 155))
            ->call('save')
            ->assertHasNoErrors(['form.description']);
    }
}
