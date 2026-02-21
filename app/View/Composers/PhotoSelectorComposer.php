<?php

declare(strict_types=1);

namespace App\View\Composers;

use Illuminate\View\View;

class PhotoSelectorComposer
{
    public function compose(View $view): void
    {
        $photoDirectory = '/images/photos';
        $publicPhotoDirectory = public_path().$photoDirectory;

        $photos = [];
        if (is_dir($publicPhotoDirectory)) {
            $photos = array_values(array_diff(scandir($publicPhotoDirectory), ['.', '..']));
        }

        $view->with([
            'photo_directory' => $photoDirectory,
            'photos' => $photos,
        ]);
    }
}
