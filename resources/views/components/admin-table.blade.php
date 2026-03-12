@props(['headers' => []])

<div class="px-6 max-w-4xl mx-auto mt-6 block w-full overflow-auto scrolling-touch">
  <table class="w-full max-w-full mb-4 bg-transparent">
    <thead>
      <tr>
        @foreach($headers as $header)
          <th>{{ $header }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      {{ $slot }}
    </tbody>
  </table>
</div>