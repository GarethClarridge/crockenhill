@extends('layouts.main')

@section('title')
{{ $title ?? $heading ?? 'Admin' }}
@stop

@section('content')
<main id="main-content" class="mb-3" tabindex="-1">

  <article>

    {{-- Page Header --}}
    <x-page-header :heading="$heading ?? 'Admin'" />

    <x-content-wrapper class="mx-auto max-w-7xl px-6 md:px-8">

      {{-- Session Messages --}}
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

      {{-- Flash Notifications (from redirects) --}}
      @if (session('notification'))
        <x-session-message :type="session('notification')['type'] ?? 'success'">
          {{ session('notification')['message'] }}
        </x-session-message>
      @endif

      {{-- Breadcrumbs --}}
      <x-breadcrumbs area="church" :heading="$breadcrumbHeading ?? $heading ?? 'Admin'" />

      {{-- Main Content: $slot for Livewire full-page components, @yield for legacy @extends consumers --}}
      <div class="mt-6">
        {{ $slot }}
        @yield('dynamic_content')
      </div>

    </x-content-wrapper>

  </article>

  {{-- Toast Notifications --}}
  <div x-data="{
      notifications: [],
      add(event) {
          const id = Date.now();
          const type = event.detail.type || 'success';
          this.notifications.push({
              id,
              type,
              message: event.detail.message
          });
          setTimeout(() => this.remove(id), 4000);
      },
      remove(id) {
          this.notifications = this.notifications.filter(n => n.id !== id);
      }
  }"
  @notify.window="add($event)"
  class="fixed top-4 right-4 z-50 space-y-2"
  aria-live="polite">
      <template x-for="notification in notifications" :key="notification.id">
          <div x-transition:enter="transform ease-out duration-300"
               x-transition:enter-start="translate-y-2 opacity-0"
               x-transition:enter-end="translate-y-0 opacity-100"
               x-transition:leave="transition ease-in duration-200"
               x-transition:leave-start="opacity-100"
               x-transition:leave-end="opacity-0"
               :class="{
                  'bg-green-50 border-green-200 text-green-800': notification.type === 'success',
                  'bg-red-50 border-red-200 text-red-800': notification.type === 'error',
                  'bg-amber-50 border-amber-200 text-amber-800': notification.type === 'warning',
                  'bg-blue-50 border-blue-200 text-blue-800': notification.type === 'info'
               }"
               :role="['error', 'warning'].includes(notification.type) ? 'alert' : 'status'"
               aria-atomic="true"
               class="rounded-lg border p-4 shadow-xl max-w-sm flex items-start gap-3">

              <div class="shrink-0">
                <x-heroicon-o-check-circle x-show="notification.type === 'success'" class="h-5 w-5 text-green-500" aria-hidden="true" />
                <x-heroicon-o-x-circle x-show="notification.type === 'error'" class="h-5 w-5 text-red-500" aria-hidden="true" />
                <x-heroicon-o-exclamation-triangle x-show="notification.type === 'warning'" class="h-5 w-5 text-amber-500" aria-hidden="true" />
                <x-heroicon-o-information-circle x-show="notification.type === 'info'" class="h-5 w-5 text-blue-500" aria-hidden="true" />
              </div>

              <div class="flex-1 pr-4">
                  <span x-text="notification.message" class="text-sm font-medium"></span>
              </div>

              <button @click="remove(notification.id)" class="shrink-0 text-current opacity-50 hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded-md transition-all" aria-label="Close notification">
                  <x-heroicon-m-x-mark class="h-4 w-4" aria-hidden="true" />
              </button>
          </div>
      </template>
  </div>

</main>
@stop
