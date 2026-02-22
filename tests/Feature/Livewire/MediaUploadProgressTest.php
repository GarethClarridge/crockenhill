<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\MediaUpload\Progress;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadProgressTest extends TestCase
{
    #[Test]
    public function it_renders_upload_progress_when_upload_is_active(): void
    {
        Livewire::test(Progress::class, [
            'isUploading' => true,
            'status' => 'uploading',
            'uploadProgress' => 42,
            'currentFileName' => 'sermon.mp3',
        ])
            ->assertSee('Uploading sermon.mp3...')
            ->assertSee('42%');
    }

    #[Test]
    public function it_hides_upload_progress_when_upload_is_not_active(): void
    {
        Livewire::test(Progress::class, [
            'isUploading' => false,
            'status' => 'idle',
            'uploadProgress' => 0,
        ])
            ->assertDontSee('Processing will start automatically when upload completes');
    }
}
