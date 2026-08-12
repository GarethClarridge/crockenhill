<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosCurationPlan;
use App\Services\Email\OosCurationManifest;
use App\Support\CurationManifestReader;
use App\Support\MarkdownFrontmatter;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OosCurationManifestTest extends TestCase
{
    private string $root;

    private string $verbatimRoot;

    private string $formattedRoot;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/oos-curation-'.bin2hex(random_bytes(6));
        $this->verbatimRoot = $this->root.'/oos-verbatim';
        $this->formattedRoot = $this->root.'/oos';
        $this->manifestPath = $this->root.'/manifest.json';

        File::makeDirectory($this->verbatimRoot, 0755, true);
        File::makeDirectory($this->formattedRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function manifest(): OosCurationManifest
    {
        return new OosCurationManifest(new CurationManifestReader, new MarkdownFrontmatter);
    }

    private function plan(): OosCurationPlan
    {
        return $this->manifest()->plan($this->verbatimRoot, $this->formattedRoot, $this->manifestPath);
    }

    /** Write a file into a root and return its path/hash/size triple. */
    private function writeSource(string $root, string $name, string $contents): array
    {
        File::put("{$root}/{$name}", $contents);

        return [$name, hash('sha256', $contents), strlen($contents)];
    }

    /**
     * A paired entry: the same order of service in both roots.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pairedEntry(string $itemKey, string $date, string $service, array $overrides = []): array
    {
        $verbatim = "---\nsource_date: {$date}\n---\n\nraw {$itemKey}";
        [$vPath, $vHash, $vSize] = $this->writeSource($this->verbatimRoot, "{$itemKey}.md", $verbatim);
        [$fPath, $fHash, $fSize] = $this->writeSource($this->formattedRoot, "{$itemKey}.md", "formatted {$itemKey}");

        return array_merge([
            'item_key' => $itemKey,
            'source_kind' => 'email',
            'verbatim_relative_path' => $vPath,
            'verbatim_sha256' => $vHash,
            'verbatim_byte_size' => $vSize,
            'formatted_relative_path' => $fPath,
            'formatted_sha256' => $fHash,
            'formatted_byte_size' => $fSize,
            'disposition' => 'include',
            'payload' => 'formatted',
            'resolved_date' => $date,
            'resolved_service' => $service,
            'date_decision' => 'explicit',
            'content_scope' => 'full',
            'parse_decision' => 'strict',
            'expected_item_count' => 9,
            'decided_by' => 'maintainer@example.test',
            'decided_at' => '2026-08-06T10:00:00+00:00',
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeManifest(array $entries, string $batchKey = 'oos-2026-08-06'): void
    {
        File::put($this->manifestPath, json_encode([
            'format' => 'crockenhill-oos-curation',
            'version' => 1,
            'batch_key' => $batchKey,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_plans_a_reconciled_corpus(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2015-12-13', '2015-12-13', 'morning'),
            $this->pairedEntry('2015-12-13-pm', '2015-12-13', 'evening'),
        ]);

        $plan = $this->plan();

        $this->assertCount(2, $plan->includes);
        $this->assertSame('oos-2026-08-06', $plan->batchKey);
        $this->assertSame(2, $plan->counts['include']);
        $this->assertSame('2015-12-13', $plan->includes[0]['source_date']);
        $this->assertSame(2, $plan->counts['full']);
        $this->assertSame(0, $plan->counts['partial']);
        $this->assertNotSame($plan->manifestHash, $plan->planHash);
    }

    #[Test]
    public function it_rejects_a_verbatim_file_no_entry_claims(): void
    {
        $this->writeManifest([$this->pairedEntry('2015-12-13', '2015-12-13', 'morning')]);
        File::put("{$this->verbatimRoot}/2022-01-02.md", 'an unruled order of service');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The OoS verbatim directory contains unmanifested files: 2022-01-02.md');

        $this->plan();
    }

    #[Test]
    public function it_rejects_a_formatted_file_no_entry_claims(): void
    {
        $this->writeManifest([$this->pairedEntry('2015-12-13', '2015-12-13', 'morning')]);
        File::put("{$this->formattedRoot}/2024-11-03.md", 'a formatted order with no entry');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The OoS formatted directory contains unmanifested files: 2024-11-03.md');

        $this->plan();
    }

    #[Test]
    public function it_rejects_content_that_changed_after_the_manifest_was_approved(): void
    {
        $entry = $this->pairedEntry('2015-12-13', '2015-12-13', 'morning');
        $this->writeManifest([$entry]);
        File::put("{$this->formattedRoot}/2015-12-13.md", 'edited after approval');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 mismatch for formatted file 2015-12-13.md.');

        $this->plan();
    }

    #[Test]
    public function it_rejects_two_active_full_orders_for_one_service(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2015-12-27', '2015-12-27', 'morning'),
            $this->pairedEntry('2015-12-27-revised', '2015-12-27', 'morning'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The 2015-12-27 morning service has more than one active full order');

        $this->plan();
    }

    #[Test]
    public function a_revised_order_supersedes_its_predecessor_and_leaves_one_active_leaf(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2015-12-27', '2015-12-27', 'morning'),
            $this->pairedEntry('2015-12-27-revised', '2015-12-27', 'morning', [
                'supersedes' => '2015-12-27',
            ]),
        ]);

        $plan = $this->plan();

        $this->assertSame(2, $plan->counts['include']);
        $this->assertSame(1, $plan->counts['superseded']);
    }

    #[Test]
    public function a_partial_order_complements_a_full_one_without_superseding_it(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2019-11-17', '2019-11-17', 'morning'),
            $this->pairedEntry('2019-11-17-hymns', '2019-11-17', 'morning', [
                'content_scope' => 'partial',
                'partial_scope_reason' => 'Hymn numbers only; the order itself was not circulated.',
                'expected_item_count' => 4,
            ]),
        ]);

        $plan = $this->plan();

        $this->assertSame(1, $plan->counts['full']);
        $this->assertSame(1, $plan->counts['partial']);
        $this->assertSame(0, $plan->counts['superseded']);
    }

    #[Test]
    public function two_partial_orders_for_one_service_are_permitted(): void
    {
        $partial = [
            'content_scope' => 'partial',
            'partial_scope_reason' => 'Fragment of the order.',
        ];

        $this->writeManifest([
            $this->pairedEntry('2019-11-17-details', '2019-11-17', 'morning', $partial),
            $this->pairedEntry('2019-11-17-hymns', '2019-11-17', 'morning', $partial),
        ]);

        $this->assertSame(2, $this->plan()->counts['partial']);
    }

    #[Test]
    public function a_partial_order_cannot_supersede(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2019-11-17', '2019-11-17', 'morning'),
            $this->pairedEntry('2019-11-17-hymns', '2019-11-17', 'morning', [
                'content_scope' => 'partial',
                'partial_scope_reason' => 'Hymn numbers only.',
                'supersedes' => '2019-11-17',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is a partial order and cannot supersede anything');

        $this->plan();
    }

    #[Test]
    public function supersession_may_not_cross_services(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2016-01-17', '2016-01-17', 'morning'),
            $this->pairedEntry('2016-01-17-pm', '2016-01-17', 'evening', [
                'supersedes' => '2016-01-17',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('which resolves to a different service');

        $this->plan();
    }

    #[Test]
    public function a_forked_supersession_chain_is_rejected(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2020-06-14', '2020-06-14', 'morning'),
            $this->pairedEntry('2020-06-14-revised', '2020-06-14', 'morning', ['supersedes' => '2020-06-14']),
            $this->pairedEntry('2020-06-14-third', '2020-06-14', 'morning', ['supersedes' => '2020-06-14']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a supersession chain forks');

        $this->plan();
    }

    #[Test]
    public function a_cyclic_supersession_chain_is_rejected(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2021-05-02', '2021-05-02', 'morning', ['supersedes' => '2021-05-02-revised']),
            $this->pairedEntry('2021-05-02-revised', '2021-05-02', 'morning', ['supersedes' => '2021-05-02']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is cyclic');

        $this->plan();
    }

    #[Test]
    public function a_named_service_must_carry_its_label(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2018-03-30-good-friday', '2018-03-30', 'other'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare service_label exactly when it resolves to other');

        $this->plan();
    }

    #[Test]
    public function a_named_service_survives_the_three_case_enum(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2018-03-30-good-friday', '2018-03-30', 'other', [
                'service_label' => 'Good Friday',
                'title_override' => 'Good Friday Joint Service',
            ]),
        ]);

        $includes = $this->plan()->includes;

        $this->assertSame('Good Friday', $includes[0]['service_label']);
        $this->assertSame('Good Friday Joint Service', $includes[0]['title_override']);
    }

    #[Test]
    public function an_ordinary_service_may_not_carry_a_service_label(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2015-12-13', '2015-12-13', 'morning', ['service_label' => 'Morning Worship']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare service_label exactly when it resolves to other');

        $this->plan();
    }

    #[Test]
    public function an_inferred_date_must_record_why(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2023-04-07', '2023-04-07', 'other', [
                'service_label' => 'Good Friday',
                'date_decision' => 'inferred',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare date_decision_reason exactly when its date is inferred');

        $this->plan();
    }

    #[Test]
    public function an_inferred_date_with_its_reason_is_accepted_and_counted(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2023-04-07', '2023-04-07', 'other', [
                'service_label' => 'Good Friday',
                'date_decision' => 'inferred',
                'date_decision_reason' => 'Derived from liturgical calendar; the heading carried no explicit date.',
            ]),
        ]);

        $this->assertSame(1, $this->plan()->counts['inferred-date']);
    }

    #[Test]
    public function an_entry_without_curation_authority_is_rejected(): void
    {
        $entry = $this->pairedEntry('2015-12-13', '2015-12-13', 'morning');
        unset($entry['decided_by'], $entry['decided_at']);
        $this->writeManifest([$entry]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares no curation authority');

        $this->plan();
    }

    #[Test]
    public function a_formatted_order_with_no_raw_counterpart_must_explain_itself(): void
    {
        [$fPath, $fHash, $fSize] = $this->writeSource($this->formattedRoot, '2024-11-03.md', 'second-hand');

        $this->writeManifest([[
            'item_key' => '2024-11-03',
            'source_kind' => 'email',
            'formatted_relative_path' => $fPath,
            'formatted_sha256' => $fHash,
            'formatted_byte_size' => $fSize,
            'disposition' => 'include',
            'payload' => 'formatted',
            'resolved_date' => '2024-11-03',
            'resolved_service' => 'morning',
            'date_decision' => 'explicit',
            'content_scope' => 'full',
            'parse_decision' => 'strict',
            'expected_item_count' => 7,
            'decided_by' => 'maintainer@example.test',
            'decided_at' => '2026-08-06T10:00:00+00:00',
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare verbatim_absence_reason exactly when it has no verbatim file');

        $this->plan();
    }

    #[Test]
    public function a_verbatim_only_entry_may_be_excluded_with_a_reason(): void
    {
        [$vPath, $vHash, $vSize] = $this->writeSource($this->verbatimRoot, '2022-01-02.md', 'never formatted');

        $this->writeManifest([[
            'item_key' => '2022-01-02',
            'source_kind' => 'email',
            'verbatim_relative_path' => $vPath,
            'verbatim_sha256' => $vHash,
            'verbatim_byte_size' => $vSize,
            'disposition' => 'exclude',
            'exclusion_reason' => 'Announcements only; the order of service was not circulated by email that week.',
            'decision_rule_version' => 'oos-curation-rules-v1',
        ]]);

        $plan = $this->plan();

        $this->assertSame(0, $plan->counts['include']);
        $this->assertSame(1, $plan->counts['exclude']);
        $this->assertSame(1, $plan->counts['verbatim-only']);
    }

    #[Test]
    public function an_excluded_entry_carrying_include_fields_is_rejected(): void
    {
        $entry = $this->pairedEntry('2022-01-02', '2022-01-02', 'morning', [
            'disposition' => 'exclude',
            'exclusion_reason' => 'Announcements only.',
        ]);
        $this->writeManifest([$entry]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('applies to includes only');

        $this->plan();
    }

    #[Test]
    public function an_excluded_entry_must_declare_its_reason(): void
    {
        [$vPath, $vHash, $vSize] = $this->writeSource($this->verbatimRoot, '2022-01-02.md', 'never formatted');

        $this->writeManifest([[
            'item_key' => '2022-01-02',
            'source_kind' => 'email',
            'verbatim_relative_path' => $vPath,
            'verbatim_sha256' => $vHash,
            'verbatim_byte_size' => $vSize,
            'disposition' => 'exclude',
            'decision_rule_version' => 'oos-curation-rules-v1',
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare exclusion_reason');

        $this->plan();
    }

    #[Test]
    public function it_rejects_a_traversal_path(): void
    {
        File::put("{$this->root}/outside.md", 'outside the root');

        $this->writeManifest([[
            'item_key' => 'escape',
            'source_kind' => 'email',
            'verbatim_relative_path' => '../outside.md',
            'verbatim_sha256' => hash('sha256', 'outside the root'),
            'verbatim_byte_size' => 16,
            'disposition' => 'exclude',
            'exclusion_reason' => 'n/a',
            'decision_rule_version' => 'oos-curation-rules-v1',
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe manifest path: ../outside.md');

        $this->plan();
    }

    #[Test]
    public function it_rejects_another_sources_entries(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2015-12-13', '2015-12-13', 'morning', ['source_kind' => 'openlp']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('this manifest curates email');

        $this->plan();
    }

    #[Test]
    public function it_rejects_a_free_text_service_from_the_corpus_frontmatter(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2015-12-27-revised', '2015-12-27', 'revised'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has an invalid resolved service');

        $this->plan();
    }

    #[Test]
    public function the_plan_hash_changes_when_a_curation_decision_changes(): void
    {
        $this->writeManifest([$this->pairedEntry('2015-12-13', '2015-12-13', 'morning')]);
        $before = $this->plan()->planHash;

        $this->writeManifest([
            $this->pairedEntry('2015-12-13', '2015-12-13', 'morning', ['expected_item_count' => 11]),
        ]);

        $this->assertNotSame($before, $this->plan()->planHash);
    }

    #[Test]
    public function the_plan_hash_is_stable_across_entry_order(): void
    {
        $first = $this->pairedEntry('2015-12-13', '2015-12-13', 'morning');
        $second = $this->pairedEntry('2015-12-13-pm', '2015-12-13', 'evening');

        $this->writeManifest([$first, $second]);
        $forward = $this->plan()->planHash;

        $this->writeManifest([$second, $first]);

        $this->assertSame($forward, $this->plan()->planHash);
    }

    #[Test]
    public function verify_includes_rehashes_the_payload_before_use(): void
    {
        $this->writeManifest([$this->pairedEntry('2015-12-13', '2015-12-13', 'morning')]);
        $plan = $this->plan();

        /**
         * Same byte length, different bytes: this proves the hash is what
         * catches the edit, not the cheaper size check standing in for it.
         */
        $original = 'formatted 2015-12-13';
        File::put("{$this->formattedRoot}/2015-12-13.md", str_pad('tampered', strlen($original), '!'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 mismatch for 2015-12-13.md.');

        $this->manifest()->verifyIncludes($this->verbatimRoot, $this->formattedRoot, $plan);
    }

    #[Test]
    public function an_item_count_may_be_omitted(): void
    {
        $entry = $this->pairedEntry('2015-12-13', '2015-12-13', 'morning');
        unset($entry['expected_item_count']);
        $this->writeManifest([$entry]);

        $this->assertNull($this->plan()->includes[0]['expected_item_count']);
    }

    #[Test]
    public function an_item_count_cannot_be_asserted_without_a_person_behind_it(): void
    {
        $entry = $this->pairedEntry('2015-12-13', '2015-12-13', 'morning', [
            'expected_item_count' => 13,
            'decision_rule_version' => 'oos-curation-draft-v1',
        ]);
        unset($entry['decided_by'], $entry['decided_at']);
        $this->writeManifest([$entry]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('asserts an expected_item_count without decided_by');

        $this->plan();
    }

    #[Test]
    public function a_strict_entry_fails_closed_when_the_source_date_contradicts_the_manifest(): void
    {
        $this->writeSourceWithFrontmatter($this->formattedRoot, '2026-03-15-2', ['date' => '2026-03-15']);
        $this->writeManifest([$this->frontmatterEntry('2026-03-15-2', '2026-02-15', 'morning')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares date 2026-03-15, which contradicts its manifest value 2026-02-15');

        $this->manifest()->validateIncludesForDryRun($this->verbatimRoot, $this->formattedRoot, $this->plan());
    }

    #[Test]
    public function a_manifest_authoritative_entry_records_the_adjudicated_disagreement(): void
    {
        $this->writeSourceWithFrontmatter($this->formattedRoot, '2026-03-15-2', ['date' => '2026-03-15']);
        $this->writeManifest([$this->frontmatterEntry('2026-03-15-2', '2026-02-15', 'morning', [
            'parse_decision' => 'manifest-authoritative',
        ])]);

        $adjudicated = $this->manifest()->validateIncludesForDryRun($this->verbatimRoot, $this->formattedRoot, $this->plan());

        $this->assertSame([[
            'item_key' => '2026-03-15-2',
            'field' => 'date',
            'manifest' => '2026-02-15',
            'source' => '2026-03-15',
        ]], $adjudicated);
    }

    #[Test]
    public function a_strict_entry_fails_closed_when_the_source_names_another_service(): void
    {
        $this->writeSourceWithFrontmatter($this->formattedRoot, '2020-01-05', ['date' => '2020-01-05', 'service' => 'pm']);
        $this->writeManifest([$this->frontmatterEntry('2020-01-05', '2020-01-05', 'morning')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares service evening, which contradicts its manifest value morning');

        $this->manifest()->validateIncludesForDryRun($this->verbatimRoot, $this->formattedRoot, $this->plan());
    }

    #[Test]
    public function the_corpus_free_text_service_frontmatter_asserts_nothing(): void
    {
        /**
         * `revised` is lineage, not a service (§7.5). A strict entry must not
         * fail merely because the corpus overloaded that field.
         */
        $this->writeSourceWithFrontmatter($this->formattedRoot, '2015-12-27-revised', [
            'date' => '2015-12-27',
            'service' => 'revised',
        ]);
        $this->writeManifest([$this->frontmatterEntry('2015-12-27-revised', '2015-12-27', 'morning')]);

        $this->assertSame([], $this->manifest()->validateIncludesForDryRun($this->verbatimRoot, $this->formattedRoot, $this->plan()));
    }

    #[Test]
    public function a_source_with_no_body_is_rejected(): void
    {
        $this->writeSourceWithFrontmatter($this->formattedRoot, '2020-01-05', ['date' => '2020-01-05'], body: "\n  \n");
        $this->writeManifest([$this->frontmatterEntry('2020-01-05', '2020-01-05', 'morning')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has an empty body');

        $this->manifest()->validateIncludesForDryRun($this->verbatimRoot, $this->formattedRoot, $this->plan());
    }

    /** @param array<string, string> $frontmatter */
    private function writeSourceWithFrontmatter(string $root, string $name, array $frontmatter, string $body = "\nWelcome\n\nHymn 190\n"): void
    {
        $lines = ['---'];
        foreach ($frontmatter as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }
        $lines[] = '---';

        File::put("{$root}/{$name}.md", implode("\n", $lines).$body);
    }

    /**
     * An entry over a file already written by writeSourceWithFrontmatter().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function frontmatterEntry(string $itemKey, string $date, string $service, array $overrides = []): array
    {
        $contents = (string) file_get_contents("{$this->formattedRoot}/{$itemKey}.md");

        return array_merge([
            'item_key' => $itemKey,
            'source_kind' => 'email',
            'formatted_relative_path' => "{$itemKey}.md",
            'formatted_sha256' => hash('sha256', $contents),
            'formatted_byte_size' => strlen($contents),
            'verbatim_absence_reason' => 'Formatted-only fixture.',
            'disposition' => 'include',
            'payload' => 'formatted',
            'resolved_date' => $date,
            'resolved_service' => $service,
            'date_decision' => 'explicit',
            'content_scope' => 'full',
            'parse_decision' => 'strict',
            'decision_rule_version' => 'oos-curation-draft-v1',
        ], $overrides);
    }

    #[Test]
    public function verify_includes_resolves_the_nominated_payload_root(): void
    {
        $this->writeManifest([
            $this->pairedEntry('2015-12-13', '2015-12-13', 'morning', ['payload' => 'verbatim']),
        ]);

        $paths = $this->manifest()->verifyIncludes($this->verbatimRoot, $this->formattedRoot, $this->plan());

        $this->assertStringStartsWith(realpath($this->verbatimRoot), $paths['2015-12-13']);
    }
}
