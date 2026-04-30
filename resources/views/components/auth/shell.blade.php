@props([
    'heading',
    'description' => null,
])

@push('title'){{ $heading }}@endpush

@push('meta_description'){{ $description ?? $heading }}@endpush

<main id="main-content" class="mb-3">

  <article>

    <x-page-header :heading="$heading" />

    <x-content-wrapper>

      @if (session('message'))
        <x-session-message type="success">
          {{ session('message') }}
        </x-session-message>
      @endif

      @if (session('status'))
        <x-session-message type="info">
          {{ session('status') }}
        </x-session-message>
      @endif

      @if (session('error'))
        <x-session-message type="error">
          {{ session('error') }}
        </x-session-message>
      @endif

      @if (session('notification'))
        <x-session-message :type="session('notification')['type'] ?? 'success'">
          {{ session('notification')['message'] }}
        </x-session-message>
      @endif

      {{ $slot }}

    </x-content-wrapper>

  </article>

</main>
