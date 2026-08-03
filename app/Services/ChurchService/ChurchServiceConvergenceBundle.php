<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Support\CanonicalJson;
use RuntimeException;

class ChurchServiceConvergenceBundle
{
    public const FORMAT = 'crockenhill-service-convergence';

    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $fingerprint
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    public function make(
        string $batchHash,
        string $mediaBundleHash,
        array $fingerprint,
        array $services,
    ): array {
        $bundle = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'batch_hash' => $batchHash,
            'media_bundle_hash' => $mediaBundleHash,
            'processing_fingerprint' => $fingerprint,
            'services' => $services,
        ];
        $bundle['bundle_hash'] = CanonicalJson::hash($bundle);

        return $this->validate($bundle);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function validate(array $bundle): array
    {
        $expectedKeys = [
            'format', 'version', 'batch_hash', 'media_bundle_hash',
            'processing_fingerprint', 'services', 'bundle_hash',
        ];
        $actualKeys = array_keys($bundle);
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('Convergence bundle has missing or unknown envelope keys.');
        }

        if ($bundle['format'] !== self::FORMAT || $bundle['version'] !== self::VERSION) {
            throw new RuntimeException('Unsupported convergence bundle format or version.');
        }

        foreach (['batch_hash', 'media_bundle_hash', 'bundle_hash'] as $field) {
            $this->requireHash($bundle[$field], $field);
        }

        if (! is_array($bundle['processing_fingerprint']) || $bundle['processing_fingerprint'] === []) {
            throw new RuntimeException('Convergence bundle processing fingerprint is missing.');
        }

        if (! is_array($bundle['services']) || ! array_is_list($bundle['services']) || $bundle['services'] === []) {
            throw new RuntimeException('Convergence bundle services must be a non-empty list.');
        }

        $expectedHash = CanonicalJson::hash(array_diff_key($bundle, ['bundle_hash' => true]));

        if (! hash_equals($expectedHash, $bundle['bundle_hash'])) {
            throw new RuntimeException('Convergence bundle hash does not match its payload.');
        }

        $identities = [];

        foreach ($bundle['services'] as $index => $service) {
            if (! is_array($service)) {
                throw new RuntimeException("Convergence service {$index} must be an object.");
            }

            foreach ([
                'date', 'service', 'evidence_set_hash', 'pre_review_hash',
                'resulting_canonical_hash', 'manual_revision', 'review',
                'canonical_manifest', 'finalization', 'projection_policy',
            ] as $field) {
                if (! array_key_exists($field, $service)) {
                    throw new RuntimeException("Convergence service {$index} is missing {$field}.");
                }
            }

            $identity = "{$service['date']}|{$service['service']}";

            if (isset($identities[$identity])) {
                throw new RuntimeException("Duplicate convergence service identity {$identity}.");
            }

            $identities[$identity] = true;
            $this->requireHash($service['evidence_set_hash'], "services.{$index}.evidence_set_hash");
            $this->requireHash($service['pre_review_hash'], "services.{$index}.pre_review_hash");
            $this->requireHash($service['resulting_canonical_hash'], "services.{$index}.resulting_canonical_hash");

            $finalization = $service['finalization'];

            if (! in_array($finalization, ['automatic', 'manual'], true)) {
                throw new RuntimeException("Convergence service {$index} has an unsupported finalization.");
            }

            $this->validateProjectionPolicy($service['projection_policy'], $index);

            if ($finalization === 'automatic' && ($service['manual_revision'] !== null || $service['review'] !== null)) {
                throw new RuntimeException("Automatic convergence service {$index} cannot contain Manual review data.");
            }

            if ($finalization === 'manual' && (! is_array($service['manual_revision']) || ! is_array($service['review']))) {
                throw new RuntimeException("Manual convergence service {$index} is missing review data.");
            }

            if ($finalization === 'manual') {
                $this->validateReview($service['review'], $index);
            }
        }

        $this->guardPortable($bundle);

        return $bundle;
    }

    private function requireHash(mixed $value, string $field): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException("{$field} must be a lowercase SHA-256.");
        }
    }

    private function validateProjectionPolicy(mixed $value, int $serviceIndex): void
    {
        if (
            ! is_array($value)
            || ! is_string($value['format'] ?? null)
            || ! is_int($value['version'] ?? null)
            || $value['version'] < 1
        ) {
            throw new RuntimeException("Convergence service {$serviceIndex} has no valid projection policy fingerprint.");
        }
    }

    /** @param array<string, mixed> $review */
    private function validateReview(array $review, int $serviceIndex): void
    {
        foreach (['review_uuid', 'reviewer_email_hash', 'service_field_decisions', 'decisions', 'proposal_dispositions', 'decision_rules'] as $field) {
            if (! array_key_exists($field, $review)) {
                throw new RuntimeException("Manual convergence service {$serviceIndex} review is missing {$field}.");
            }
        }

        if (! is_string($review['review_uuid']) || $review['review_uuid'] === '') {
            throw new RuntimeException("Manual convergence service {$serviceIndex} review UUID is invalid.");
        }

        if (! is_string($review['reviewer_email_hash']) || preg_match('/\A[a-f0-9]{64}\z/', $review['reviewer_email_hash']) !== 1) {
            throw new RuntimeException("Manual convergence service {$serviceIndex} reviewer identity is invalid.");
        }

        if (! is_array($review['proposal_dispositions']) || ! array_is_list($review['proposal_dispositions'])) {
            throw new RuntimeException("Manual convergence service {$serviceIndex} proposal dispositions must be a list.");
        }

        $identities = [];

        foreach ($review['proposal_dispositions'] as $dispositionIndex => $disposition) {
            if (! is_array($disposition)
                || ! is_string($disposition['proposal_identity'] ?? null)
                || $disposition['proposal_identity'] === ''
                || ! in_array($disposition['disposition'] ?? null, ['accepted', 'rejected', 'replaced'], true)
                || (($disposition['rationale'] ?? null) !== null && ! is_string($disposition['rationale']))) {
                throw new RuntimeException("Manual convergence service {$serviceIndex} proposal disposition {$dispositionIndex} is invalid.");
            }

            if (isset($identities[$disposition['proposal_identity']])) {
                throw new RuntimeException("Manual convergence service {$serviceIndex} repeats a proposal disposition.");
            }

            $identities[$disposition['proposal_identity']] = true;
        }

        $this->validateDecisionRules($review['decision_rules'], $serviceIndex, $identities);
    }

    /**
     * A rule may only name proposals this service actually dispositioned, so a bundle
     * cannot smuggle in an authorising act for proposals it does not carry.
     *
     * @param  array<string, bool>  $dispositionedIdentities
     */
    private function validateDecisionRules(mixed $rules, int $serviceIndex, array $dispositionedIdentities): void
    {
        if (! is_array($rules) || ! array_is_list($rules)) {
            throw new RuntimeException("Manual convergence service {$serviceIndex} decision rules must be a list.");
        }

        foreach ($rules as $ruleIndex => $rule) {
            if (! is_array($rule)
                || ! is_string($rule['class_key'] ?? null)
                || $rule['class_key'] === ''
                || ! in_array($rule['disposition'] ?? null, ['accepted', 'rejected', 'replaced'], true)
                || ! is_string($rule['rationale'] ?? null)
                || trim($rule['rationale']) === ''
                || ! is_array($rule['proposal_identities'] ?? null)
                || ! array_is_list($rule['proposal_identities'])
                || (($rule['match_tier'] ?? null) !== null && ! is_int($rule['match_tier']))) {
                throw new RuntimeException("Manual convergence service {$serviceIndex} decision rule {$ruleIndex} is invalid.");
            }

            $covered = array_filter(
                $rule['proposal_identities'],
                static fn (mixed $identity): bool => is_string($identity) && isset($dispositionedIdentities[$identity]),
            );

            if ($covered === []) {
                throw new RuntimeException(
                    "Manual convergence service {$serviceIndex} decision rule {$ruleIndex} names no proposal this service dispositioned.",
                );
            }
        }
    }

    private function guardPortable(mixed $value, string $path = 'bundle'): void
    {
        if (! is_array($value)) {
            if (is_string($value) && (str_starts_with($value, '/') || str_starts_with($value, 'file://'))) {
                throw new RuntimeException("{$path} contains a local path.");
            }

            return;
        }

        foreach ($value as $key => $nested) {
            $nestedPath = "{$path}.{$key}";

            if (
                is_string($key)
                && ! in_array($key, ['source_assertion_hashes'], true)
                && preg_match('/(^|_)(id|ids)$/i', $key) === 1
            ) {
                throw new RuntimeException("{$nestedPath} contains a local database identity.");
            }

            if (is_string($key) && preg_match('/(secret|token|path|queue|job|attempt|retry)/i', $key) === 1) {
                throw new RuntimeException("{$nestedPath} contains forbidden runtime or path data.");
            }

            $this->guardPortable($nested, $nestedPath);
        }
    }
}
