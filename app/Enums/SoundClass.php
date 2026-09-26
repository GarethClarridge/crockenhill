<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the audio classifier hears in one window of a recording.
 *
 * Music and speech are scored independently, so a window can hold both: a reading over a song's
 * outro is `Mixed`, not speech. See {@see \App\Services\Media\Audio\AudioTimeline}.
 */
enum SoundClass: string
{
    case Music = 'music';
    case Mixed = 'mixed';
    case Speech = 'speech';
    case Neither = 'neither';

    public static function fromScores(float $music, float $speech, float $musicCutoff, float $speechCutoff): self
    {
        $isMusic = $music >= $musicCutoff;
        $isSpeech = $speech >= $speechCutoff;

        return match (true) {
            $isMusic && $isSpeech => self::Mixed,
            $isMusic => self::Music,
            $isSpeech => self::Speech,
            default => self::Neither,
        };
    }
}
