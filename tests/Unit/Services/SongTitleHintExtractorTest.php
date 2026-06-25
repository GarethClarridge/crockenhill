<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionType;
use App\Services\Song\SongTitleHintExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SongTitleHintExtractorTest extends TestCase
{
    private SongTitleHintExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new SongTitleHintExtractor;
    }

    // ──────────────────────────────────────────────────────────────
    // Title extraction: happy paths
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function announcementTranscriptProvider(): array
    {
        return [
            '"let\'s stand and sing together" pattern' => [
                "Let's stand and sing together Though the Nations Rage.",
                'Though the Nations Rage',
            ],
            '"going to sing" pattern' => [
                'We are going to sing Your Word.',
                'Your Word',
            ],
            '"song entitled" pattern' => [
                'We will sing a song entitled Your Word.',
                'Your Word',
            ],
            '"song called" pattern' => [
                'The next song is called In Christ Alone.',
                'In Christ Alone',
            ],
            '"sing" with quoted title' => [
                "Let's sing 'Amazing Grace' together.",
                'Amazing Grace',
            ],
            '"hymn entitled" pattern' => [
                'We will now sing hymn entitled Great Is Thy Faithfulness.',
                'Great Is Thy Faithfulness',
            ],
        ];
    }

    #[Test]
    #[DataProvider('announcementTranscriptProvider')]
    public function it_extracts_title_from_announcement_transcripts(string $transcript, string $expectedHint): void
    {
        $sections = [
            $this->makeSpeechSongSection($transcript),
            $this->makeAudioOnlySongSection(),
        ];

        $result = $this->extractor->extract($sections);

        $this->assertSame($expectedHint, $result[1]['metadata']['song_title_hint']);
    }

    #[Test]
    public function it_extracts_title_from_ai_notes_when_transcript_has_no_match(): void
    {
        $sections = [
            $this->makeSpeechSongSection(
                transcript: 'Please turn to the next song.',
                aiNotes: ['Congregation sings "How Great Thou Art"'],
            ),
            $this->makeAudioOnlySongSection(),
        ];

        $result = $this->extractor->extract($sections);

        $this->assertSame('How Great Thou Art', $result[1]['metadata']['song_title_hint']);
    }

    // ──────────────────────────────────────────────────────────────
    // No match cases
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function it_does_not_write_hint_when_no_title_can_be_extracted(): void
    {
        $sections = [
            $this->makeSpeechSongSection('Please stand.'),
            $this->makeAudioOnlySongSection(),
        ];

        $result = $this->extractor->extract($sections);

        $this->assertArrayNotHasKey('song_title_hint', $result[1]['metadata']);
    }

    #[Test]
    public function it_does_not_write_hint_when_following_section_is_not_audio_only_song(): void
    {
        $sections = [
            $this->makeSpeechSongSection("Let's sing Your Word."),
            [
                'section_type' => ServiceSectionType::Song->value,
                'metadata' => ['classification_mode' => 'ai_transcript'],
            ],
        ];

        $result = $this->extractor->extract($sections);

        $this->assertArrayNotHasKey('song_title_hint', $result[1]['metadata']);
    }

    #[Test]
    public function it_does_not_write_hint_when_announcement_has_no_following_section(): void
    {
        $sections = [
            $this->makeSpeechSongSection("Let's sing Your Word."),
        ];

        // Should not throw; simply no mutation.
        $result = $this->extractor->extract($sections);

        $this->assertCount(1, $result);
    }

    #[Test]
    public function it_does_not_touch_non_song_sections(): void
    {
        $sections = [
            [
                'section_type' => ServiceSectionType::Sermon->value,
                'metadata' => [
                    'classification_mode' => 'ai_transcript',
                    'transcript' => "Let's sing Your Word.",
                ],
            ],
            $this->makeAudioOnlySongSection(),
        ];

        $result = $this->extractor->extract($sections);

        $this->assertArrayNotHasKey('song_title_hint', $result[1]['metadata']);
    }

    // ──────────────────────────────────────────────────────────────
    // Multi-section sequences
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function it_writes_hints_for_multiple_independent_song_announcements(): void
    {
        $sections = [
            $this->makeSpeechSongSection("Let's sing Your Word."),
            $this->makeAudioOnlySongSection(),
            $this->makeSpeechSongSection("We'll now sing In Christ Alone."),
            $this->makeAudioOnlySongSection(),
        ];

        $result = $this->extractor->extract($sections);

        $this->assertSame('Your Word', $result[1]['metadata']['song_title_hint']);
        $this->assertSame('In Christ Alone', $result[3]['metadata']['song_title_hint']);
    }

    #[Test]
    public function it_does_not_overwrite_a_hint_on_a_non_adjacent_song(): void
    {
        // announcement → non-song section → audio-only song (no hint expected on the song)
        $sections = [
            $this->makeSpeechSongSection("Let's sing Your Word."),
            [
                'section_type' => ServiceSectionType::Prayer->value,
                'metadata' => ['classification_mode' => 'ai_transcript'],
            ],
            $this->makeAudioOnlySongSection(),
        ];

        $result = $this->extractor->extract($sections);

        $this->assertArrayNotHasKey('song_title_hint', $result[2]['metadata']);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $aiNotes
     * @return array{section_type: string, metadata: array<string, mixed>}
     */
    private function makeSpeechSongSection(string $transcript, array $aiNotes = []): array
    {
        return [
            'section_type' => ServiceSectionType::Song->value,
            'metadata' => [
                'classification_mode' => 'ai_transcript',
                'transcript' => $transcript,
                'ai_notes' => $aiNotes,
            ],
        ];
    }

    /**
     * @return array{section_type: string, metadata: array<string, mixed>}
     */
    private function makeAudioOnlySongSection(): array
    {
        return [
            'section_type' => ServiceSectionType::Song->value,
            'metadata' => [
                'classification_mode' => 'audio_only',
            ],
        ];
    }
}
