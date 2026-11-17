<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\TalkTypeAutomaticConfirmation;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TalkTypeAutomaticConfirmationTest extends TestCase
{
    private TalkTypeAutomaticConfirmation $confirmation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmation = new TalkTypeAutomaticConfirmation;
    }

    /** @return array<string, mixed> */
    private function record(string $type, float $confidence, array $overrides = []): array
    {
        return [...[
            'proposed' => $type, 'disposition' => 'proposed', 'confidence' => $confidence,
            'proposal_status' => 'completed', 'signals' => ['conflicts' => []],
        ], ...$overrides];
    }

    /** @return array<string, array{string}> */
    public static function confirmedTypes(): array
    {
        return ['childrens talk' => ['childrens_talk'], 'testimony' => ['testimony']];
    }

    #[Test]
    #[DataProvider('confirmedTypes')]
    public function it_confirms_a_clean_high_confidence_type(string $type): void
    {
        $result = $this->confirmation->apply($this->record($type, 0.95));

        $this->assertSame($type, $result['reviewed']['value']);
        $this->assertSame('automatic', $result['reviewed']['source']);
        $this->assertSame('T12', $result['reviewed']['rule']);
        $this->assertSame(0.95, $result['reviewed']['confidence']);
        $this->assertArrayNotHasKey('series', $result['reviewed']);
        $this->assertArrayHasKey('at', $result['reviewed']);
    }

    #[Test]
    public function it_leaves_other_proposals_as_questions(): void
    {
        $cases = [
            $this->record('childrens_talk', 0.94),
            $this->record('partner_update', 0.99),
            $this->record('church_update', 0.99),
            $this->record('sermon', 0.99),
            $this->record('childrens_talk', 0.99, ['signals' => ['conflicts' => ['bound_title_source_disagree']]]),
            $this->record('childrens_talk', 0.99, ['proposal_status' => 'unresolved']),
            ['disposition' => 'uncertain', 'proposal_status' => 'completed'],
            ['disposition' => 'insufficient_evidence', 'proposal_status' => 'completed'],
        ];

        foreach ($cases as $record) {
            $this->assertArrayNotHasKey('reviewed', $this->confirmation->apply($record));
        }
    }

    #[Test]
    public function it_withholds_a_not_a_talk_proposal(): void
    {
        $result = $this->confirmation->apply(['disposition' => 'not_a_talk', 'proposal_status' => 'completed']);

        $this->assertSame('none', $result['reviewed']['value']);
        $this->assertSame('automatic', $result['reviewed']['source']);
        $this->assertSame('T11', $result['reviewed']['rule']);
    }

    #[Test]
    public function it_never_touches_an_operators_answer(): void
    {
        foreach ([['value' => 'church_update', 'user_id' => 3, 'at' => 'x'], ['value' => 'none', 'user_id' => 3]] as $reviewed) {
            $record = $this->record('childrens_talk', 0.99, ['reviewed' => $reviewed]);

            $this->assertSame($record, $this->confirmation->apply($record));
        }
    }

    #[Test]
    public function it_is_idempotent_and_keeps_the_original_time(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $first = $this->confirmation->apply($this->record('testimony', 0.97));
        Carbon::setTestNow('2026-11-18 10:00:00');

        $this->assertSame($first, $this->confirmation->apply($first));
        Carbon::setTestNow();
    }

    #[Test]
    public function it_demotes_an_automatic_answer_the_proposal_no_longer_supports(): void
    {
        $confirmed = $this->confirmation->apply($this->record('childrens_talk', 0.97));
        $changed = [...$confirmed, ...$this->record('partner_update', 0.99)];

        $this->assertArrayNotHasKey('reviewed', $this->confirmation->apply($changed));
    }

    #[Test]
    public function it_replaces_an_automatic_answer_when_the_decision_changes(): void
    {
        $confirmed = $this->confirmation->apply($this->record('childrens_talk', 0.97));
        $changed = $this->confirmation->apply([...$confirmed, ...$this->record('testimony', 0.98)]);

        $this->assertSame('testimony', $changed['reviewed']['value']);
    }
}
