<?php

namespace Crockenhill\Services; // Assuming Crockenhill is the main namespace

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log; // Optional: for logging

class MeetingImageService
{
    /**
     * The disk where meeting images are stored (within public path).
     */
    protected string $diskName = 'public'; // Assumes 'public' disk maps to public_path()

    /**
     * Base path for meeting images within the disk.
     * Note: Storage facade paths are relative to the disk's root.
     * If 'public' disk root is public_path(), then this path is correct.
     */
    protected string $basePath = 'images/meetings';

    /**
     * Renames a meeting's image directory from an old slug to a new slug.
     *
     * @param string $oldSlug The current slug of the meeting.
     * @param string $newSlug The new slug for the meeting.
     * @return bool True if rename was attempted (or successful), false if old directory didn't exist or error.
     */
    public function renameImageDirectory(string $oldSlug, string $newSlug): bool
    {
        if (empty($oldSlug) || empty($newSlug) || $oldSlug === $newSlug) {
            return false; // No action needed or invalid input
        }

        $oldDirectoryPath = $this->basePath . '/' . $oldSlug;
        $newDirectoryPath = $this->basePath . '/' . $newSlug;

        if (Storage::disk($this->diskName)->exists($oldDirectoryPath)) {
            try {
                // Ensure the parent directory of the new path exists
                // Usually $this->basePath should exist, but being thorough.
                // Storage::move should ideally handle creating parent if it can, but let's be safe.
                // $parentOfNew = dirname($newDirectoryPath);
                // if (!Storage::disk($this->diskName)->exists($parentOfNew)) {
                //     Storage::disk($this->diskName)->makeDirectory($parentOfNew);
                // }
                // Not strictly necessary if $this->basePath always exists.

                Storage::disk($this->diskName)->move($oldDirectoryPath, $newDirectoryPath);
                // Optional: Log success
                // Log::info("Moved meeting image directory from '{$oldDirectoryPath}' to '{$newDirectoryPath}'.");
                return true;
            } catch (\Exception $e) {
                // Optional: Log the error
                Log::error("Error moving meeting image directory '{$oldDirectoryPath}' to '{$newDirectoryPath}': " . $e->getMessage());
                return false;
            }
        } else {
            // Optional: Log that old directory was not found
            // Log::info("Meeting image directory '{$oldDirectoryPath}' not found. No rename action needed for directory.");
            return false; // Old directory didn't exist, so nothing to move.
        }
    }
}
