<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

/**
 * Decoding options sent with every local whisper.cpp request.
 *
 * whisper-server carries all earlier text forward as context by default. On long
 * recordings the decoder then locks into its own output: whole sermons came back
 * as one-word "Word." segments (runs 1314, 1343, 1258) and as repeated lines
 * that replaced real speech (1358). A 180 s slice of the same audio transcribed
 * cleanly on its own, so the fault is the carried context, not the recording.
 * With no carried context, and the initial prompt re-sent for every window,
 * seven whole services lost both faults with timings unchanged (median 0.1-0.4 s).
 * Evidence: storage/scratch/blind-20260916-comparison/bc08-20260917/.
 */
class LocalWhisperDecoding
{
    /** @var array{max_context: string, carry_initial_prompt: string} */
    public const OPTIONS = [
        'max_context' => '0',
        'carry_initial_prompt' => 'true',
    ];
}
