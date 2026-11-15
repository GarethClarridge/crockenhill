<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\TalkTypeInput;
use App\Enums\TalkType;
use App\Services\ChurchService\TalkTypeEvidenceExtractor;
use App\Services\ChurchService\TalkTypeRules;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TalkTypeRulesTest extends TestCase
{
    #[Test]
    public function bound_t7_teaching_items_decide_and_record_series_without_a_model(): void
    {
        foreach (['Catechism: Question 42' => 'Catechism', 'Hero of Faith: An unknown believer' => 'Heroes of Faith', 'Bible-character talk: Deborah' => 'Bible Characters'] as $title => $series) {
            $input = $this->input(['item' => $this->item($title)]);
            $result = (new TalkTypeRules)->evaluate($input);
            $this->assertSame(TalkType::ChildrensTalk, $result->proposed);
            $this->assertSame($series, $result->proposedSeries);
            $this->assertSame('T7.bound_item', $result->ruleId);
            $this->assertSame('rule', $result->decidedBy);
            $this->assertFalse($result->signals['needs_model']);
            $this->assertNotEmpty($result->reasons);
        }
    }

    #[Test]
    public function only_current_positive_series_declarations_decide(): void
    {
        $rules = new TalkTypeRules;
        $result = $rules->evaluate($this->input(['text' => 'Today we continue our Heroes of Faith series. Let us learn from a faithful nurse.']));
        $this->assertSame('T7.declared_series', $result->ruleId);
        $this->assertSame('Heroes of Faith', $result->proposedSeries);
        foreach ([
            'Last year we continued our Heroes of Faith series.',
            'Today we are not continuing our Heroes of Faith series.',
            'If we continued our Heroes of Faith series, we might discuss a nurse.',
            'She said, "Today we continue our Heroes of Faith series."',
            'Good morning to look at some of the great heroes of faith I will mention the name later.',
            'Today we continue our Heroes of Faith series, or perhaps we do not.',
        ] as $text) {
            $result = $rules->evaluate($this->input(['text' => $text]));
            $this->assertNull($result->proposed, $text);
            $this->assertTrue($result->signals['needs_model'], $text);
        }
    }

    #[Test]
    public function recommendations_projected_deleted_and_conflicting_items_are_not_current_teaching_evidence(): void
    {
        foreach ([
            $this->item('Book recommendation: Catechism'),
            $this->item('Introduction to next week: Bible Characters'),
            array_replace($this->item('Catechism'), ['deleted' => true]),
            array_replace($this->item('Catechism'), ['detection_only' => true]),
            array_replace($this->item('Catechism'), ['source_title' => 'Book recommendation']),
        ] as $item) {
            $result = (new TalkTypeRules)->evaluate($this->input(['item' => $item]));
            $this->assertNull($result->proposed);
        }
    }

    #[Test]
    public function testimony_requires_a_personal_frame_and_the_whole_account_and_retains_g10_risk(): void
    {
        $rules = new TalkTypeRules;
        $result = $rules->evaluate($this->input(['text' => 'This is my testimony. I once rejected Jesus. I came to trust Christ. Now I follow Jesus.']));
        $this->assertSame(TalkType::Testimony, $result->proposed);
        $this->assertSame('personal_testimony', $result->ruleId);
        $this->assertTrue($result->signals['testimony_risk']);
        $this->assertTrue($result->signals['g10_withheld_by_default']);
        foreach ([
            'We visited a coastal mission. I was encouraged by the conversations.',
            'This is my testimony. We ran a programme overseas and need volunteers.',
            'This is my testimony. I once rejected Jesus. She came to trust Christ.',
            'She told us, "This is my testimony. I came to trust Christ."',
            'I will not tell my testimony again. We will teach children at camp next month.',
        ] as $text) {
            $result = $rules->evaluate($this->input(['text' => $text, 'title' => 'Testimony', 'item' => $this->item('Testimony')]));
            $this->assertNull($result->proposed, $text);
            $this->assertTrue($result->signals['testimony_risk']);
        }
    }

    #[Test]
    public function duration_is_a_versioned_feature_and_a_shorter_later_sermon_can_be_main(): void
    {
        $rules = new TalkTypeRules;
        $input = $this->input(['text' => 'This passage teaches us to pray. Therefore let us pray faithfully.', 'outline' => [$this->entry(0, 700, 'short_talk', 'main_address')]]);
        $result = $rules->evaluate($input);
        $this->assertSame(TalkType::Sermon, $result->proposed);
        $this->assertSame('service.main_address', $result->ruleId);
        $this->assertSame(962.665, $result->signals['role_features']['full_length_calibration_seconds']);
        $this->assertSame(TalkTypeRules::VERSION, $result->provenance['rules_version']);
        foreach ([962.664, 962.665, 1100.01] as $duration) {
            $result = $rules->evaluate($this->input(['outline' => [$this->entry(0, 700, 'short_talk', 'main_address'), $this->entry(800, $duration, 'sermon')]]));
            $this->assertNull($result->proposed);
            $this->assertSame($duration >= 962.665, $result->signals['role_features']['full_length_elsewhere']);
        }
        $result = $rules->evaluate($this->input(['outline' => [$this->entry(0, 764.715, 'short_talk'), $this->entry(900, 825.32, 'sermon')]]));
        $this->assertNull($result->proposed);
        $this->assertTrue($result->signals['role_features']['separate_main_address']);
        $result = $rules->evaluate($this->input(['outline' => [$this->entry(0, 1400, 'short_talk')]]));
        $this->assertNull($result->proposed, 'Longest speech alone does not establish main-address purpose.');
    }

    #[Test]
    public function positively_established_morning_family_slot_does_not_require_a_child_audience(): void
    {
        foreach ([['item' => $this->item('Family Talk: A word for parents')], ['text' => 'This is our family talk. Parents, consider how you care for one another.']] as $changes) {
            $result = (new TalkTypeRules)->evaluate($this->input($changes));
            $this->assertSame(TalkType::ChildrensTalk, $result->proposed);
            $this->assertSame('T9.morning_family_slot', $result->ruleId);
        }
        $this->assertNull((new TalkTypeRules)->evaluate($this->input())->proposed);
        $this->assertNull((new TalkTypeRules)->evaluate($this->input(['service' => null, 'item' => $this->item('Family Talk')]))->proposed);
    }

    #[Test]
    public function evening_veto_covers_every_family_path_and_preserves_series_and_risk(): void
    {
        foreach ([
            ['item' => $this->item('Catechism: Question 4')],
            ['text' => 'Today we continue our Bible Characters series.'],
            ['item' => $this->item('Family Talk')],
            ['text' => $this->childrenTeaching()],
        ] as $changes) {
            $result = (new TalkTypeRules)->evaluate($this->input(array_replace($changes, ['service' => 'evening'])));
            $this->assertNull($result->proposed);
            $this->assertSame('T9.evening_veto', $result->ruleId);
            $this->assertTrue($result->signals['evening_veto']);
        }
        $result = (new TalkTypeRules)->evaluate($this->input(['service' => 'evening', 'item' => $this->item('Catechism')]));
        $this->assertSame('Catechism', $result->proposedSeries);
    }

    #[Test]
    public function children_must_be_addressed_here_with_teaching_and_a_separate_address(): void
    {
        $rules = new TalkTypeRules;
        $result = $rules->evaluate($this->input(['text' => $this->childrenTeaching()]));
        $this->assertSame(TalkType::ChildrensTalk, $result->proposed);
        $this->assertSame('current_children_teaching', $result->ruleId);
        foreach ([
            'We said to the children, "Boys and girls, listen carefully." Jesus teaches us to forgive. So we should forgive each other.',
            'Next week I will tell the children that Jesus teaches us to forgive. So we should forgive each other.',
            'Boys and girls, show us your models. Thank you for bringing them.',
        ] as $text) {
            $this->assertNull($rules->evaluate($this->input(['text' => $text]))->proposed);
        }
        $this->assertNull($rules->evaluate($this->input(['text' => $this->childrenTeaching(), 'outline' => [$this->entry(0, 400, 'short_talk')]]))->proposed);
    }

    #[Test]
    public function an_explicit_notice_needs_whole_text_corroboration(): void
    {
        $rules = new TalkTypeRules;
        $result = $rules->evaluate($this->input(['text' => 'This is a notice. The lunch is at noon in the hall. Please sign up at the desk.']));
        $this->assertSame('not_a_talk', $result->disposition);
        $this->assertSame('function.explicit_non_address', $result->ruleId);
        $this->assertNull($result->proposed);
        $result = $rules->evaluate($this->input(['text' => 'This is a notice. The lunch is at noon in the hall. Jesus teaches us to forgive. So we should forgive each other.']));
        $this->assertSame('uncertain', $result->disposition);
        $this->assertNull($result->confidence);
    }

    #[Test]
    public function incomplete_speech_cannot_be_used_as_negative_evidence_and_retains_t7_finding(): void
    {
        foreach ([['text' => null], ['transcript' => ['excerpt_only' => true]], ['transcript' => ['boundary_overlap' => true]], ['transcript' => ['unobservable_windows' => [['start' => 20, 'end' => 40]]]]] as $changes) {
            $input = $this->input($changes);
            $result = (new TalkTypeRules)->evaluate($input);
            $this->assertSame('insufficient_evidence', $result->disposition);
            $this->assertNull($result->proposed);
        }
        $result = (new TalkTypeRules)->evaluate($this->input(['text' => null, 'item' => $this->item('Catechism')]));
        $this->assertSame('Catechism', $result->proposedSeries);
        $this->assertNull($result->proposed);
        $result = (new TalkTypeRules)->evaluate($this->input(['transcript' => ['unobservable_windows' => [['start' => 600, 'end' => 650]]], 'item' => $this->item('Catechism')]));
        $this->assertSame(TalkType::ChildrensTalk, $result->proposed, 'A window elsewhere is not missing speech in this section.');
    }

    #[Test]
    public function all_evidence_is_collected_before_conflicts_are_resolved(): void
    {
        $rules = new TalkTypeRules;
        $result = $rules->evaluate($this->input(['item' => $this->item('Catechism'), 'text' => 'This is my testimony. I once rejected Jesus. I came to trust Christ. Now I follow Jesus.']));
        $this->assertSame(TalkType::ChildrensTalk, $result->proposed);
        $this->assertTrue($result->signals['testimony_risk']);
        $this->assertNotEmpty($result->signals['evidence']['testimony']);
        foreach ([
            ['identity_conflict' => true],
            ['outline' => [$this->entry(0, 400, 'short_talk', 'main_address')]],
            ['text' => 'Today we continue our Heroes of Faith series.'],
        ] as $changes) {
            $result = $rules->evaluate($this->input(array_replace(['item' => $this->item('Catechism')], $changes)));
            $this->assertNull($result->proposed);
            $this->assertNotEmpty($result->signals['conflicts']);
        }
    }

    #[Test]
    public function audit_ids_dates_previous_proposals_and_organisation_names_do_not_decide(): void
    {
        $base = $this->input(['text' => 'An unfamiliar organisation spoke about recent work.']);
        $changed = new TalkTypeInput(99999, $base->evidence, ['date' => '2020-01-01', 'run_id' => 944, 'speaker' => 'Known person'], ['classifier' => 'different'], 'childrens_talk');
        $this->assertSame((new TalkTypeRules)->evaluate($base)->toArray(), (new TalkTypeRules)->evaluate($changed)->toArray());
        $this->assertNull((new TalkTypeRules)->evaluate($base)->proposed);
    }

    #[Test]
    public function extracted_evidence_has_verbatim_sources_and_no_input_mutation(): void
    {
        $input = $this->input(['text' => 'Today we continue our Catechism series.', 'item' => $this->item('Catechism')]);
        $before = $input->toArray();
        $evidence = (new TalkTypeEvidenceExtractor)->extract($input);
        $this->assertSame('Catechism', $evidence['series'][0]['quote']);
        $this->assertSame('item.title', $evidence['series'][0]['source']);
        $this->assertSame($before, $input->toArray());
        $this->assertSame($evidence, (new TalkTypeRules)->evaluate($input)->signals['evidence']);
    }

    #[Test]
    public function frozen_study_regressions_remain_conservative_without_memorised_exceptions(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../../Fixtures/TalkType/rules-study.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            $input = new TalkTypeInput(1, $fixture['evidence'], []);
            $result = (new TalkTypeRules)->evaluate($input);
            $this->assertSame($fixture['expected'], $result->proposed?->value, $fixture['source_section']);
            if ($fixture['source_section'] === '1726') {
                $this->assertTrue($result->signals['testimony_risk']);
            }
            if ($fixture['source_section'] === '4775') {
                $this->assertSame('insufficient_evidence', $result->disposition);
            }
        }
    }

    #[Test]
    public function later_contradictory_declarations_are_not_hidden_by_the_first_positive_match(): void
    {
        foreach ([
            ['text' => 'Today we continue our Catechism series. Today we are not continuing our Catechism series.'],
            ['item' => $this->item('Catechism'), 'text' => 'Today we are not continuing our Catechism series.'],
            ['item' => $this->item('Catechism'), 'text' => 'This is not our Catechism series.'],
            ['item' => $this->item('Family Talk'), 'text' => 'This is not our family talk.'],
            ['text' => 'This is our family talk. This talk is not the family slot.'],
        ] as $changes) {
            $result = (new TalkTypeRules)->evaluate($this->input($changes));
            $this->assertNull($result->proposed);
            $this->assertNotEmpty($result->signals['conflicts']);
        }
    }

    #[Test]
    public function repeated_teaching_without_application_does_not_establish_a_family_lesson(): void
    {
        $result = (new TalkTypeRules)->evaluate($this->input(['text' => 'Boys and girls, listen carefully. Jesus teaches us to forgive. Jesus teaches us to forgive.']));
        $this->assertNull($result->proposed);
    }

    #[Test]
    public function conflicting_series_are_preserved_as_evidence_without_arbitrarily_selecting_one(): void
    {
        $result = (new TalkTypeRules)->evaluate($this->input(['item' => $this->item('Catechism'), 'text' => 'Today we continue our Heroes of Faith series.']));
        $this->assertNull($result->proposedSeries);
        $this->assertCount(2, $result->signals['evidence']['series']);
    }

    #[Test]
    public function an_introduction_cannot_become_a_sermon_by_claiming_the_main_role(): void
    {
        $result = (new TalkTypeRules)->evaluate($this->input(['text' => 'This is our main address.', 'outline' => [$this->entry(0, 30, 'short_talk', 'main_address')]]));
        $this->assertNull($result->proposed);
        $this->assertSame('uncertain', $result->disposition);
    }

    #[Test]
    public function missing_or_invalid_outline_cannot_establish_a_rule_decision(): void
    {
        foreach ([[], [$this->entry(20, -10, 'short_talk')], [$this->entry(0, 400, 'short_talk'), ['section_type' => 'sermon']]] as $outline) {
            $result = (new TalkTypeRules)->evaluate($this->input(['item' => $this->item('Catechism'), 'outline' => $outline]));
            $this->assertNull($result->proposed);
            $this->assertSame('insufficient_evidence', $result->disposition);
        }
    }

    /** @param array<string, mixed> $changes */
    private function input(array $changes = []): TalkTypeInput
    {
        return new TalkTypeInput(1, array_replace([
            'text' => 'Let us consider faithful service together.', 'title' => 'Reflection', 'series' => null,
            'item' => null, 'service' => 'morning', 'identity_conflict' => false, 'section_index' => 0,
            'outline' => [$this->entry(0, 400, 'short_talk'), $this->entry(500, 1200, 'sermon')],
            'transcript' => ['status' => 'available', 'excerpt_only' => false, 'boundary_overlap' => false, 'unobservable_windows' => []],
            'limitations' => [],
        ], $changes, ['transcript' => array_replace(['status' => 'available', 'excerpt_only' => false, 'boundary_overlap' => false, 'unobservable_windows' => []], $changes['transcript'] ?? [])]), []);
    }

    /** @return array<string, mixed> */
    private function item(string $title): array
    {
        return ['title' => $title, 'source_title' => null, 'series' => null, 'source' => 'email', 'deleted' => false, 'detection_only' => false, 'has_normalized_evidence' => true];
    }

    /** @return array<string, mixed> */
    private function entry(float $start, float $duration, string $type, ?string $role = null): array
    {
        return ['start' => $start, 'end' => $start + $duration, 'duration' => $duration, 'section_type' => $type, 'role' => $role, 'text' => null];
    }

    private function childrenTeaching(): string
    {
        return 'Boys and girls, listen carefully. Jesus teaches us to forgive. So we should forgive each other.';
    }
}
