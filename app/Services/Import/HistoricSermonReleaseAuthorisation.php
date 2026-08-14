<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * The F29 batch gate: the separate authority that turns quarantined historic
 * content public.
 *
 * This is deliberately *not* {@see HistoricImportProductionGuard}. That guard
 * requires the operation to hold the measured ingress/queue freeze, while
 * {@see HistoricImportOperationCloseout} refuses to complete an operation whose
 * ingress window is still open. Release happens after closeout, so the import
 * approval can never authorise it — which is why F29 asks for a "separately
 * authorised release exercise" rather than a second use of the import token.
 *
 * The plan (§F.6) also requires release to be "a controlled editorial batch with
 * owner, rollback and observation period — not a side effect of import", so the
 * artifact names its owners and its observation window, and enumerates the exact
 * records it releases. Membership is exact for the same reason F53 rejects
 * scalar counts: a total cannot prove *which* rows became public.
 *
 * **One maintainer may sign this alone.** D10 removed every multi-person control
 * from the historic import programme; the three roles below are accountability
 * fields, not three humans, and nothing here compares them for uniqueness. The
 * controls that survived are the ones a single operator can actually satisfy and
 * that still bite: exact enumerated membership, the deployed release identifier,
 * an unexpired window, an operation in `Complete`, and a rollback observation
 * period that outlasts the authorisation.
 *
 * @see docs/plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md — D10
 */
final class HistoricSermonReleaseAuthorisation
{
    /**
     * @return array{
     *     format: string,
     *     version: int,
     *     authorisation_id: string,
     *     operation_id: string,
     *     target_fingerprint: string,
     *     release_identifier: string,
     *     expires_at: string,
     *     batch_key: string,
     *     sermon_ids: list<int>,
     *     song_video_ids: list<int>,
     *     song_usage_report_ids: list<int>,
     *     declared_counts: array{sermons: int, song_videos: int, song_usage_reports: int},
     *     roles: array{release_owner: string, independent_verifier: string, rollback_owner: string},
     *     observation_ends_at: string,
     *     signature: array<string, mixed>
     * }
     */
    public function authorize(string $path, string $signingKey): array
    {
        $authorisation = $this->decode($path);

        $this->exactKeys($authorisation, [
            'format', 'version', 'authorisation_id', 'operation_id', 'target_fingerprint',
            'release_identifier', 'expires_at', 'batch_key', 'sermon_ids', 'song_video_ids',
            'song_usage_report_ids', 'declared_counts', 'roles', 'observation_ends_at', 'signature',
        ], 'release authorisation');

        if ($authorisation['format'] !== 'crockenhill-historic-release-authorisation'
            || $authorisation['version'] !== 1) {
            throw new RuntimeException('The historic release authorisation format is unsupported.');
        }

        if (! is_string($authorisation['authorisation_id']) || trim($authorisation['authorisation_id']) === '') {
            throw new RuntimeException('The historic release authorisation id is missing.');
        }

        if (! is_string($authorisation['batch_key']) || trim($authorisation['batch_key']) === '') {
            throw new RuntimeException('The historic release authorisation batch key is missing.');
        }

        $this->assertSignature($authorisation, $signingKey);
        $operation = $this->resolveCompleteOperation($authorisation);
        $this->assertRelease($authorisation);
        $expiresAt = $this->assertWindow($authorisation);
        $this->assertRoles($authorisation);
        $this->assertMembership($authorisation);

        if ($authorisation['target_fingerprint'] !== $operation->target_fingerprint
            || $authorisation['target_fingerprint'] !== app(HistoricImportTargetFingerprint::class)->hash()) {
            throw new RuntimeException('The release authorisation is not bound to the resolved operation and target.');
        }

        $this->assertObservation($authorisation, $expiresAt);

        /** @var array{format: string, version: int, authorisation_id: string, operation_id: string, target_fingerprint: string, release_identifier: string, expires_at: string, batch_key: string, sermon_ids: list<int>, song_video_ids: list<int>, song_usage_report_ids: list<int>, declared_counts: array{sermons: int, song_videos: int, song_usage_reports: int}, roles: array{release_owner: string, independent_verifier: string, rollback_owner: string}, observation_ends_at: string, signature: array<string, mixed>} $authorisation */
        return $authorisation;
    }

    public function operationFor(string $operationId): HistoricImportOperation
    {
        $operation = HistoricImportOperation::query()
            ->where('operation_id', $operationId)
            ->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException('The release authorisation operation does not exist.');
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The historic release authorisation artifact is missing.');
        }

        try {
            $authorisation = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The historic release authorisation artifact is invalid JSON.', previous: $exception);
        }

        if (! is_array($authorisation)) {
            throw new RuntimeException('The historic release authorisation artifact must be an object.');
        }

        return $authorisation;
    }

    /** @param array<string, mixed> $authorisation */
    private function assertSignature(array $authorisation, string $signingKey): void
    {
        $signature = $authorisation['signature'];
        $expected = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($authorisation, ['signature' => true])),
            $signingKey,
        );

        if ($signingKey === '' || ! is_array($signature)
            || ($signature['algorithm'] ?? null) !== 'hmac-sha256'
            || ! is_string($signature['key_id'] ?? null)
            || ! is_string($signature['digest'] ?? null)
            || ! hash_equals($expected, $signature['digest'])) {
            throw new RuntimeException('The historic release authorisation signature is invalid.');
        }
    }

    /**
     * Release is only meaningful once the operation carries its exact audit and
     * complete no-op rerun, which is exactly what reaching Complete proves.
     *
     * @param  array<string, mixed>  $authorisation
     */
    private function resolveCompleteOperation(array $authorisation): HistoricImportOperation
    {
        if (! is_string($authorisation['operation_id']) || trim($authorisation['operation_id']) === '') {
            throw new RuntimeException('The release authorisation names no immutable operation.');
        }

        $operation = $this->operationFor($authorisation['operation_id']);

        if ($operation->state !== HistoricImportOperationState::Complete) {
            throw new RuntimeException(
                'Historic content is released only after its operation passes exact closeout; '
                ."operation state is {$operation->state->value}.",
            );
        }

        return $operation;
    }

    /** @param array<string, mixed> $authorisation */
    private function assertRelease(array $authorisation): void
    {
        $release = config('app.release_identifier');

        if (! is_string($release) || $authorisation['release_identifier'] !== trim($release)) {
            throw new RuntimeException('The release authorisation does not authorize this deployed release.');
        }
    }

    /** @param array<string, mixed> $authorisation */
    private function assertWindow(array $authorisation): DateTimeImmutable
    {
        try {
            $expiresAt = new DateTimeImmutable((string) $authorisation['expires_at']);
        } catch (\Throwable) {
            throw new RuntimeException('The release authorisation expiry is invalid.');
        }

        if ($expiresAt <= new DateTimeImmutable) {
            throw new RuntimeException('The release authorisation has expired.');
        }

        return $expiresAt;
    }

    /**
     * The three roles are accountability records, not three people. D10 removed
     * every multi-person control from this programme because Crockenhill has one
     * maintainer: the same name may hold `release_owner`, `independent_verifier`
     * and `rollback_owner`, and these names are deliberately not compared for
     * uniqueness. Do not reinstate a distinctness check — see the class docblock.
     *
     * What still carries weight here is {@see self::assertObservation()}: the
     * rollback owner has to remain on the hook after the authorisation expires.
     * That is a real constraint on one person, and it is the half worth keeping.
     *
     * @param  array<string, mixed>  $authorisation
     */
    private function assertRoles(array $authorisation): void
    {
        $roles = $authorisation['roles'];

        if (! is_array($roles)) {
            throw new RuntimeException('The release authorisation has no named batch owners.');
        }

        $this->exactKeys($roles, [
            'release_owner', 'independent_verifier', 'rollback_owner',
        ], 'release authorisation roles');

        foreach ($roles as $person) {
            if (! is_string($person) || trim($person) === '') {
                throw new RuntimeException('Every release authorisation role must name its owner.');
            }
        }
    }

    /**
     * The rollback owner has to still be on the hook after the window in which
     * the batch could be authorised, or "release with rollback" is a formality.
     *
     * @param  array<string, mixed>  $authorisation
     */
    private function assertObservation(array $authorisation, DateTimeImmutable $expiresAt): void
    {
        try {
            $observationEndsAt = new DateTimeImmutable((string) $authorisation['observation_ends_at']);
        } catch (\Throwable) {
            throw new RuntimeException('The release authorisation observation window is invalid.');
        }

        if ($observationEndsAt <= $expiresAt) {
            throw new RuntimeException('The release observation window must outlast the authorisation itself.');
        }
    }

    /**
     * Exact membership, declared twice.
     *
     * The id lists are the authority; the declared counts are an independent
     * check that catches a truncated or partially edited artifact whose lists
     * still look structurally valid.
     *
     * @param  array<string, mixed>  $authorisation
     */
    private function assertMembership(array $authorisation): void
    {
        $sermonIds = $this->identityList($authorisation['sermon_ids'], 'sermon_ids');
        $songVideoIds = $this->identityList($authorisation['song_video_ids'], 'song_video_ids');
        $songUsageReportIds = $this->identityList($authorisation['song_usage_report_ids'], 'song_usage_report_ids');

        if ($sermonIds === [] && $songVideoIds === [] && $songUsageReportIds === []) {
            throw new RuntimeException('The release authorisation releases nothing.');
        }

        $counts = $authorisation['declared_counts'];

        if (! is_array($counts)) {
            throw new RuntimeException('The release authorisation has no declared counts.');
        }

        $this->exactKeys($counts, ['sermons', 'song_videos', 'song_usage_reports'], 'release authorisation declared counts');

        if ($counts['sermons'] !== count($sermonIds)
            || $counts['song_videos'] !== count($songVideoIds)
            || $counts['song_usage_reports'] !== count($songUsageReportIds)) {
            throw new RuntimeException('The release authorisation declared counts do not match its enumerated records.');
        }
    }

    /**
     * @return list<int>
     */
    private function identityList(mixed $values, string $label): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new RuntimeException("The release authorisation {$label} must be a list.");
        }

        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                throw new RuntimeException("The release authorisation {$label} must contain positive integer ids.");
            }
        }

        if (count(array_unique($values)) !== count($values)) {
            throw new RuntimeException("The release authorisation {$label} contains duplicate ids.");
        }

        /** @var list<int> $values */
        return $values;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function exactKeys(array $value, array $keys, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            throw new RuntimeException("The {$label} has missing or unknown fields.");
        }
    }
}
