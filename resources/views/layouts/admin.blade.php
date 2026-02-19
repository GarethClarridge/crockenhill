@extends('layouts.main')

@section('title')
{{ $title ?? $heading ?? 'Admin' }}
@stop

@section('content')
<main id="main-content" class="mb-3">

  <article>

    {{-- Page Header --}}
    <x-page-header :heading="$heading ?? 'Admin'" />

    <x-content-wrapper>

      {{-- Session Messages --}}
      @if (session('message'))
        <x-session-message>
          {{ session('message') }}
        </x-session-message>
      @endif

      {{-- Flash Notifications (from redirects) --}}
      @if (session('notification'))
        <x-session-message>
          {{ session('notification')['message'] }}
        </x-session-message>
      @endif

      {{-- Breadcrumbs --}}
      <x-breadcrumbs area="church" :heading="$heading ?? 'Admin'" />

      {{-- Main Content --}}
      <div class="mt-6">
        {{ $slot }}
      </div>

    </x-content-wrapper>

  </article>

  {{-- Toast Notifications --}}
  <div x-data="{
      notifications: [],
      add(event) {
          const id = Date.now();
          this.notifications.push({ id, type: event.detail.type, message: event.detail.message });
          setTimeout(() => this.remove(id), 4000);
      },
      remove(id) {
          this.notifications = this.notifications.filter(n => n.id !== id);
      }
  }"
  @notify.window="add($event)"
  class="fixed top-4 right-4 z-50 space-y-2">
      <template x-for="notification in notifications" :key="notification.id">
          <div x-transition:enter="transform ease-out duration-300"
               x-transition:enter-start="translate-y-2 opacity-0"
               x-transition:enter-end="translate-y-0 opacity-100"
               x-transition:leave="transition ease-in duration-200"
               x-transition:leave-start="opacity-100"
               x-transition:leave-end="opacity-0"
               :class="{
                  'bg-green-50 border-green-400 text-green-800': notification.type === 'success',
                  'bg-red-50 border-red-400 text-red-800': notification.type === 'error'
               }"
               class="rounded-md border p-4 shadow-lg max-w-sm">
              <div class="flex items-center gap-2">
                  <span x-text="notification.message"></span>
                  <button @click="remove(notification.id)" class="ml-auto text-current opacity-50 hover:opacity-100">&times;</button>
              </div>
          </div>
      </template>
  </div>

</main>
@stop
