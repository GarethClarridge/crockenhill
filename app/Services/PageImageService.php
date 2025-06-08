<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class PageImageService
{
    private $storageDisk = 'public_images';

    /**
     * Handles the upload and processing of a page heading image.
     *
     * @param UploadedFile $file The uploaded file instance.
     * @param string $slug The slug of the page.
     * @return void
     */
    public function handleImageUpload(UploadedFile $file, string $slug): void
    {
        $largePathDir = 'images/headings/large';
        $smallPathDir = 'images/headings/small';

        // Ensure directories exist
        Storage::disk($this->storageDisk)->makeDirectory($largePathDir);
        Storage::disk($this->storageDisk)->makeDirectory($smallPathDir);

        $image = Image::make($file->getRealPath());

        // Make large image for article
        $image->resize(2000, null, function ($constraint) {
            $constraint->aspectRatio();
            // $constraint->upsize(); // Original code did not explicitly prevent upsize, but usually resize doesn't upsize unless specified.
        });
        Storage::disk($this->storageDisk)->put($largePathDir . '/' . $slug . '.jpg', (string) $image->encode('jpg'));

        // Make smaller image for aside
        $image->resize(300, null, function ($constraint) {
            $constraint->aspectRatio();
            // $constraint->upsize();
        });
        Storage::disk($this->storageDisk)->put($smallPathDir . '/' . $slug . '.jpg', (string) $image->encode('jpg'));
    }

    /**
     * Deletes the heading images associated with a slug.
     *
     * @param string $slug The slug of the page.
     * @return void
     */
    public function deleteImages(string $slug): void
    {
        $largePath = 'images/headings/large/' . $slug . '.jpg';
        $smallPath = 'images/headings/small/' . $slug . '.jpg';

        if (Storage::disk($this->storageDisk)->exists($largePath)) {
            Storage::disk($this->storageDisk)->delete($largePath);
        }

        if (Storage::disk($this->storageDisk)->exists($smallPath)) {
            Storage::disk($this->storageDisk)->delete($smallPath);
        }
    }
}
