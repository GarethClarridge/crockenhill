<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\MediaType;
use App\Enums\PreacherSource;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use JsonException;
use RuntimeException;

class SermonPromotionBundleValidator
{
    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function decodeAndValidate(string $json): array
    {
        $bundle = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($bundle)) {
            throw new RuntimeException('Promotion bundle root must be a JSON object.');
        }

        $validator = Validator::make($bundle, $this->rules());

        if ($validator->fails()) {
            $messages = array_slice($validator->errors()->all(), 0, 10);

            throw new RuntimeException('Promotion bundle validation failed: '.implode(' ', $messages));
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        $this->guardNormalizedAliases($validated);

        return $validated;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        $sermonKeys = implode(',', [
            'date', 'service', 'content_type', 'audio_file_path', 'video_file_path',
            'video_quality_status', 'video_quality_reason', 'video_visibility_override',
            'video_quality_assessed_at', 'source_type', 'segment_start_time', 'segment_end_time',
            'duration', 'filetype', 'title', 'slug', 'reference', 'preacher', 'preacher_source',
            'preacher_confidence', 'needs_preacher_review', 'series', 'points', 'show_points',
            'transcript_file_path', 'thumbnail_file_path', 'thumbnail_generated_at',
            'thumbnail_metadata', 'summary', 'meta_description', 'show_summary', 'created_at', 'updated_at',
        ]);
        $provenanceKeys = implode(',', [
            'processing_id', 'processing_type', 'status', 'current_step', 'original_filename',
            'file_hash', 'file_size', 'duration', 'extracted_date', 'extracted_service',
            'source_file_path', 'audio_file_path', 'enhanced_audio_file_path', 'video_file_path',
            'transcript_file_path', 'sermon_start_time', 'sermon_end_time', 'started_at',
            'completed_at', 'is_degraded_completion', 'created_at', 'updated_at', 'steps',
        ]);

        return [
            '' => ['array:format,version,generated_at,sermons'],
            'format' => ['required', 'string', Rule::in([SermonPromotionBundleExporter::FORMAT])],
            'version' => ['required', 'integer', Rule::in([SermonPromotionBundleExporter::VERSION])],
            'generated_at' => ['required', 'date'],
            'sermons' => ['required', 'array', 'min:1', 'max:100'],
            'sermons.*' => ['required', 'array:local_id,sermon,preacher,provenance,scripture_filters,assets'],
            'sermons.*.local_id' => ['required', 'integer', 'min:1', 'distinct'],
            'sermons.*.sermon' => ['required', "array:{$sermonKeys}"],
            'sermons.*.sermon.date' => ['required', 'date_format:Y-m-d'],
            'sermons.*.sermon.service' => ['nullable', Rule::enum(SermonService::class)],
            'sermons.*.sermon.content_type' => ['required', Rule::in([SermonContentType::Sermon->value])],
            'sermons.*.sermon.audio_file_path' => ['required', 'string', 'max:255'],
            'sermons.*.sermon.video_file_path' => ['nullable', 'string', 'max:500'],
            'sermons.*.sermon.video_quality_status' => ['nullable', Rule::enum(SermonVideoQualityStatus::class)],
            'sermons.*.sermon.video_quality_reason' => ['nullable', 'string', 'max:64'],
            'sermons.*.sermon.video_visibility_override' => ['nullable', Rule::enum(SermonVideoVisibilityOverride::class)],
            'sermons.*.sermon.video_quality_assessed_at' => ['nullable', 'date'],
            'sermons.*.sermon.source_type' => ['required', Rule::in([SermonSourceType::AudioUpload->value])],
            'sermons.*.sermon.segment_start_time' => ['nullable', 'numeric', 'min:0'],
            'sermons.*.sermon.segment_end_time' => ['nullable', 'numeric', 'min:0'],
            'sermons.*.sermon.duration' => ['nullable', 'numeric', 'min:0'],
            'sermons.*.sermon.filetype' => ['required', 'string', 'max:8'],
            'sermons.*.sermon.title' => ['required', 'string', 'max:255'],
            'sermons.*.sermon.slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', 'distinct'],
            'sermons.*.sermon.reference' => ['nullable', 'string', 'max:255'],
            'sermons.*.sermon.preacher' => ['required', 'string', 'max:255'],
            'sermons.*.sermon.preacher_source' => ['nullable', Rule::enum(PreacherSource::class)],
            'sermons.*.sermon.preacher_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'sermons.*.sermon.needs_preacher_review' => ['required', 'boolean'],
            'sermons.*.sermon.series' => ['nullable', 'string', 'max:255'],
            'sermons.*.sermon.points' => ['nullable', 'array'],
            'sermons.*.sermon.show_points' => ['required', 'boolean'],
            'sermons.*.sermon.transcript_file_path' => ['nullable', 'string', 'max:255'],
            'sermons.*.sermon.thumbnail_file_path' => ['nullable', 'string', 'max:255'],
            'sermons.*.sermon.thumbnail_generated_at' => ['nullable', 'date'],
            'sermons.*.sermon.thumbnail_metadata' => ['nullable', 'array'],
            'sermons.*.sermon.summary' => ['nullable', 'string'],
            'sermons.*.sermon.meta_description' => ['nullable', 'string', 'max:255'],
            'sermons.*.sermon.show_summary' => ['required', 'boolean'],
            'sermons.*.sermon.created_at' => ['required', 'date'],
            'sermons.*.sermon.updated_at' => ['required', 'date'],
            'sermons.*.preacher' => ['required', 'array:name,slug,aliases'],
            'sermons.*.preacher.name' => ['required', 'string', 'max:255'],
            'sermons.*.preacher.slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'sermons.*.preacher.aliases' => ['required', 'array', 'max:100'],
            'sermons.*.preacher.aliases.*' => ['required', 'string', 'max:255', 'distinct'],
            'sermons.*.provenance' => ['required', "array:{$provenanceKeys}"],
            'sermons.*.provenance.processing_id' => ['required', 'uuid', 'distinct'],
            'sermons.*.provenance.processing_type' => ['required', Rule::in([MediaType::Audio->value])],
            'sermons.*.provenance.status' => ['required', Rule::in([ProcessingStatus::Completed->value])],
            'sermons.*.provenance.current_step' => ['nullable', 'string', 'max:255'],
            'sermons.*.provenance.original_filename' => ['required', 'string', 'max:255'],
            'sermons.*.provenance.file_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/', 'distinct'],
            'sermons.*.provenance.file_size' => ['required', 'integer', 'min:1'],
            'sermons.*.provenance.duration' => ['nullable', 'numeric', 'min:0'],
            'sermons.*.provenance.extracted_date' => ['nullable', 'date_format:Y-m-d'],
            'sermons.*.provenance.extracted_service' => ['nullable', Rule::enum(SermonService::class)],
            'sermons.*.provenance.source_file_path' => ['nullable', 'string', 'max:255'],
            'sermons.*.provenance.audio_file_path' => ['nullable', 'string', 'max:255'],
            'sermons.*.provenance.enhanced_audio_file_path' => ['nullable', 'string', 'max:255'],
            'sermons.*.provenance.video_file_path' => ['nullable', 'string', 'max:255'],
            'sermons.*.provenance.transcript_file_path' => ['nullable', 'string', 'max:255'],
            'sermons.*.provenance.sermon_start_time' => ['nullable', 'numeric', 'min:0'],
            'sermons.*.provenance.sermon_end_time' => ['nullable', 'numeric', 'min:0'],
            'sermons.*.provenance.started_at' => ['nullable', 'date'],
            'sermons.*.provenance.completed_at' => ['required', 'date'],
            'sermons.*.provenance.is_degraded_completion' => ['required', 'boolean'],
            'sermons.*.provenance.created_at' => ['required', 'date'],
            'sermons.*.provenance.updated_at' => ['required', 'date'],
            'sermons.*.provenance.steps' => ['required', 'array', 'min:1', 'max:100'],
            'sermons.*.provenance.steps.*' => ['required', 'array:step,status,message,started_at,completed_at,created_at,updated_at'],
            'sermons.*.provenance.steps.*.step' => ['required', 'string', 'max:255'],
            'sermons.*.provenance.steps.*.status' => ['required', Rule::enum(ProcessingStatus::class)],
            'sermons.*.provenance.steps.*.message' => ['nullable', 'string'],
            'sermons.*.provenance.steps.*.started_at' => ['nullable', 'date'],
            'sermons.*.provenance.steps.*.completed_at' => ['nullable', 'date'],
            'sermons.*.provenance.steps.*.created_at' => ['required', 'date'],
            'sermons.*.provenance.steps.*.updated_at' => ['required', 'date'],
            'sermons.*.scripture_filters' => ['required', 'array', 'max:100'],
            'sermons.*.scripture_filters.*' => ['required', 'array:bible_book,bible_chapter'],
            'sermons.*.scripture_filters.*.bible_book' => ['required', 'string', 'max:50'],
            'sermons.*.scripture_filters.*.bible_chapter' => ['required', 'integer', 'min:1', 'max:65535'],
            'sermons.*.assets' => ['required', 'array', 'min:1', 'max:100'],
            'sermons.*.assets.*' => ['required', 'array:kind,path,size,sha256'],
            'sermons.*.assets.*.kind' => ['required', 'string', Rule::in(SermonPromotionAssets::KINDS)],
            'sermons.*.assets.*.path' => ['required', 'string', 'max:500'],
            'sermons.*.assets.*.size' => ['required', 'integer', 'min:0'],
            'sermons.*.assets.*.sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function guardNormalizedAliases(array $bundle): void
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $bundle['sermons'];

        foreach ($entries as $entry) {
            /** @var array{name: string, slug: string, aliases: list<string>} $preacher */
            $preacher = $entry['preacher'];

            foreach ($preacher['aliases'] as $alias) {
                if ($alias !== Str::lower(Str::squish($alias))) {
                    throw new RuntimeException('Promotion bundle preacher aliases must be normalized lower-case values.');
                }
            }
        }
    }
}
