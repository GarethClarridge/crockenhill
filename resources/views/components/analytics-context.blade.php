{{--
    Analytics context marker (GA4).

    Emits a single hidden element whose data-ga-* attributes describe the content
    on the page. resources/js/analytics.js reads [data-ga-context] and merges these
    into page_view and sermon_play as event params / content_group, so GA4 can
    group by preacher, series, service, and content type once the matching custom
    dimensions are registered (see docs/operations/SEO_SETUP_GUIDE.md).

    Shared across content types (sermons, children's corner) so every player page
    supplies the same dimensions consistently.
--}}
@props([
    'sermon',
    'preacherName' => null,
    'serviceLabel' => null,
])

@php
    $isChildrensTalk = $sermon->content_type === \App\Enums\SermonContentType::ChildrensTalk;

    $preacher = filled($preacherName) ? $preacherName : $sermon->preacher;

    $service = $serviceLabel;
    if ($service === null && $sermon->service !== null) {
        $service = $sermon->service instanceof \App\Enums\SermonService
            ? $sermon->service->label()
            : \Illuminate\Support\Str::title((string) $sermon->service);
    }

    $contentGroup = $isChildrensTalk ? "Children's Corner" : 'Sermons';
@endphp

<div
    data-ga-context
    @if(filled($preacher)) data-ga-preacher="{{ $preacher }}" @endif
    @if(filled($sermon->series)) data-ga-series="{{ $sermon->series }}" @endif
    @if(filled($service)) data-ga-service="{{ $service }}" @endif
    data-ga-content-type="{{ $sermon->content_type->value }}"
    data-ga-content-group="{{ $contentGroup }}"
    hidden
></div>
