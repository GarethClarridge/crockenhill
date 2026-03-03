@props([
'area',
'heading',
])

@php
use Illuminate\Support\Str;

$breadcrumbItems = [];
$breadcrumbItems[] = ['name' => 'Home', 'item' => url('/')];

// Handle admin routes (e.g., /admin/pages, /admin/sermons)
if (\Request::segment(1) === 'admin') {
$breadcrumbItems[] = ['name' => 'Church', 'item' => url('church')];
$breadcrumbItems[] = ['name' => 'Members', 'item' => url('church/members')];

// Add section breadcrumb for admin subsections
if (count(\Request::segments()) >= 2) {
$section = \Request::segment(2);
$sectionName = match($section) {
'pages' => 'Pages',
'sermons' => 'Sermons',
'meetings' => 'Meetings',
'calendar-events' => 'Calendar Events',
'sermon-upload' => 'Upload Sermon',
default => Str::title(str_replace('-', ' ', $section)),
};
// Only add section breadcrumb if we're deeper than the section index
if (count(\Request::segments()) >= 3) {
$breadcrumbItems[] = ['name' => $sectionName, 'item' => url('admin/' . $section)];
}
}
} elseif (count(\Request::segments()) >= 2) {
$breadcrumbItems[] = ['name' => Str::title($area), 'item' => url($area)];

if (count(\Request::segments()) >= 3) {
if (\Request::segment(2) === 'sermons') {
$breadcrumbItems[] = ['name' => 'Sermons', 'item' => url('christ/sermons')];
if (count(\Request::segments()) === 4) {
$breadcrumbItems[] = ['name' => Str::title(\Request::segment(3)), 'item' => url('christ/sermons/' . \Request::segment(3))];
}
} elseif (\Request::segment(2) === 'members') {
$breadcrumbItems[] = ['name' => 'Members', 'item' => url('church/members')];
}
}
}

$breadcrumbItems[] = ['name' => $heading, 'item' => url()->current()];

$breadcrumbList = [
'@context' => 'https://schema.org',
'@type' => 'BreadcrumbList',
'itemListElement' => array_map(function ($item, $index) {
return [
'@type' => 'ListItem',
'position' => $index + 1,
'name' => $item['name'],
'item' => $item['item'],
];
}, $breadcrumbItems, array_keys($breadcrumbItems)),
];
@endphp

<script type="application/ld+json">
  {!! json_encode($breadcrumbList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>

<div class="my-6 flex flex-wrap items-center justify-between gap-4" x-data="{
    copied: false,
    copy() {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText('{{ url()->current() }}').then(() => {
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        });
    }
}">
  <nav aria-label="Breadcrumb">
    <ol class="inline-flex flex-wrap items-center space-x-1 md:space-x-2">
      @foreach ($breadcrumbItems as $index => $item)
    @if ($index === 0)
    {{-- Home item with icon --}}
    <li class="inline-flex items-center">
      <a href="{{ $item['item'] }}" wire:navigate class="inline-flex items-center">
        <svg class="w-3 h-3 me-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
          <path d="m19.707 9.293-2-2-7-7a1 1 0 0 0-1.414 0l-7 7-2 2a1 1 0 0 0 1.414 1.414L2 10.414V18a2 2 0 0 0 2 2h3a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h3a2 2 0 0 0 2-2v-7.586l.293.293a1 1 0 0 0 1.414-1.414Z" />
        </svg>
        {{ $item['name'] }}
      </a>
    </li>
    @elseif ($index === count($breadcrumbItems) - 1)
    {{-- Current page (last item) --}}
    <li aria-current="page" class="flex items-center">
      <svg class="w-3 h-3 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4" />
      </svg>
      <span class="ms-1 md:ms-2">
        {{ $item['name'] }}
      </span>
    </li>
    @else
    {{-- Middle items --}}
    <li class="flex items-center">
      <svg class="w-3 h-3 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4" />
      </svg>
      <a href="{{ $item['item'] }}" wire:navigate class="ms-1 md:ms-2">
        {{ $item['name'] }}
      </a>
    </li>
    @endif
      @endforeach
    </ol>
  </nav>

  <button
    type="button"
    x-show="navigator.clipboard"
    @click="copy()"
    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-cbc-teal-dark hover:text-cbc-teal bg-white border border-gray-200 hover:border-cbc-teal-light/30 rounded-md shadow-sm transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-1"
    aria-label="Copy page link"
    title="Copy link to clipboard"
    x-cloak
  >
    <x-heroicon-o-link x-show="!copied" class="w-4 h-4" />
    <x-heroicon-o-check x-show="copied" class="w-4 h-4 text-cbc-teal" x-cloak />
    <span x-text="copied ? 'Copied!' : 'Copy link'"></span>
  </button>
</div>