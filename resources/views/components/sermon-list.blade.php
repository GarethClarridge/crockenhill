@props(['sermons', 'groupedByDate' => false])

@if($groupedByDate)
  {{-- Date grouped sermons (for index.blade.php and all.blade.php) --}}
  @foreach ($sermons as $date => $dateSermons)
    <section id="week-{{$date}}" class="px-6 max-w-2xl lg:max-w-5xl xl:max-w-7xl mx-auto mb-8">
      <x-h2>
        {{ date_format(date_create($dateSermons[0]->date),'l jS F') }}
      </x-h2>
      <div class="grid items-start justify-center gap-2 [grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),19rem))]">
        @foreach ($dateSermons as $sermon)
          <x-sermon-card :$sermon/>
        @endforeach
      </div>
    </section>
  @endforeach
@else
  {{-- Simple sermon list (for preacher.blade.php, series.blade.php, service.blade.php) --}}
  <div class="mx-auto mb-6 grid max-w-2xl items-start justify-center gap-2 px-6 [grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),19rem))] lg:max-w-5xl xl:max-w-7xl">
    @foreach ($sermons as $sermon)
      <x-sermon-card :$sermon/>
    @endforeach
  </div>
@endif
