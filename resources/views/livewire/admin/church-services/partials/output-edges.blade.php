{{-- "Start here / end here" for a sermon's or talk's output: expects $section and $edgePanel
     ({@see ReviewsServiceSections::outputEdgePanelFor()}). Times are recording (service) time. --}}
@php
    $check = $this->outputEdgeChecks[$section->id] ?? null;
    $outputLabel = $section->section_type === \App\Enums\ServiceSectionType::ShortTalk ? 'talk' : 'sermon';
@endphp

<x-card heading="Where this {{ $outputLabel }} starts and ends" dusk="output-edges-{{ $section->id }}">
    <div class="space-y-4">
        <p class="text-sm text-gray-700">
            Listen around each edge and set the time you hear the {{ $outputLabel }} start or end. The next cut uses it after every
            automatic edge rule. Nothing is cut when you save, and a content hold stays until it is released on the release page.
        </p>

        @foreach(['start' => 'Start', 'end' => 'End'] as $edge => $edgeLabel)
            @php
                $edgeData = $edgePanel['edges'][$edge];
                $answer = $edgeData['answer'];
                $checked = is_array($check) ? $check['edges'][$edge] : null;
                $timeField = "outputEdgeTimes.{$section->id}.{$edge}";
            @endphp

            <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-3" aria-labelledby="output-edge-{{ $section->id }}-{{ $edge }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h4 id="output-edge-{{ $section->id }}-{{ $edge }}" class="text-sm font-semibold text-gray-900">{{ $edgeLabel }}</h4>
                    <p class="text-xs text-gray-600 tabular-nums">
                        Section {{ $edge }}: {{ \App\Support\ServiceTimestamp::format($edgeData['bound']) }}
                        @if($answer)
                            · <span class="font-semibold text-cbc-teal-dark" data-output-edge-answer>Set at {{ \App\Support\ServiceTimestamp::format((float) $answer['time']) }}</span>
                            <span class="text-gray-500">({{ \Illuminate\Support\Carbon::parse($answer['set_at'])->format('j M Y H:i') }})</span>
                        @endif
                    </p>
                </div>

                @if($edgePanel['audio_url'])
                    <div x-data="{
                            from: {{ $edgeData['clip']['start'] }},
                            until: {{ $edgeData['clip']['end'] }},
                            edge: {{ $edgeData['around'] }},
                            position: {{ $edgeData['clip']['start'] }},
                            playing: false,
                            rate: (() => { try { return Number(localStorage.getItem('cbc-listening-rate')) || 3 } catch (error) { return 3 } })(),
                            toggle() {
                                const audio = this.$refs.audio;
                                if (! audio.paused) { audio.pause(); return; }
                                if (audio.currentTime < this.from || audio.currentTime >= this.until) { audio.currentTime = this.from; }
                                audio.playbackRate = this.rate;
                                audio.play();
                            },
                            seek(time) {
                                const audio = this.$refs.audio;
                                audio.currentTime = Math.max(0, time);
                                this.position = audio.currentTime;
                                audio.playbackRate = this.rate;
                                audio.play();
                            },
                            setRate(rate) {
                                this.rate = rate;
                                this.$refs.audio.playbackRate = rate;
                                try { localStorage.setItem('cbc-listening-rate', String(rate)) } catch (error) {}
                            },
                            format(seconds) {
                                const tenths = Math.round(Math.max(0, seconds) * 10);
                                const whole = Math.floor(tenths / 10);
                                const hours = Math.floor(whole / 3600);
                                const minutes = Math.floor((whole % 3600) / 60);
                                const secs = String(whole % 60).padStart(2, '0');
                                const fraction = tenths % 10 === 0 ? '' : '.' + (tenths % 10);
                                return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${secs}${fraction}` : `${minutes}:${secs}${fraction}`;
                            },
                        }"
                        class="space-y-2">
                        <audio x-ref="audio" src="{{ $edgePanel['audio_url'] }}" preload="none" class="hidden"
                            x-on:timeupdate="position = $event.target.currentTime; if (position >= until) { $event.target.pause() }"
                            x-on:play="playing = true" x-on:pause="playing = false"></audio>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-form-button type="button" size="sm" variant="outline" class="min-h-11" x-on:click="toggle()" x-bind:aria-pressed="playing">
                                <span x-text="playing ? 'Pause' : 'Play'">Play</span>
                            </x-form-button>
                            <x-form-button type="button" size="sm" variant="outline" class="min-h-11" x-on:click="seek(position - 5)" aria-label="Back five seconds">−5 s</x-form-button>
                            <x-form-button type="button" size="sm" variant="outline" class="min-h-11" x-on:click="seek(position + 5)" aria-label="Forward five seconds">+5 s</x-form-button>
                            <x-form-button type="button" size="sm" variant="outline" class="min-h-11" x-on:click="seek(edge - 5)">From 5 s before the {{ $edge }}</x-form-button>
                            <span class="font-mono text-sm tabular-nums text-gray-900" aria-live="off" x-text="format(position)">{{ \App\Support\ServiceTimestamp::format($edgeData['clip']['start']) }}</span>
                            <div class="flex gap-1" role="group" aria-label="Playback speed">
                                @foreach([1, 2, 3] as $speed)
                                    <x-form-button type="button" size="sm" variant="ghost" class="min-h-11 min-w-11"
                                        x-on:click="setRate({{ $speed }})"
                                        x-bind:class="rate === {{ $speed }} ? 'bg-cbc-teal-light/30 font-semibold text-cbc-teal-dark' : ''"
                                        x-bind:aria-pressed="rate === {{ $speed }}">{{ $speed }}×</x-form-button>
                                @endforeach
                            </div>
                            <x-form-button type="button" size="sm" variant="secondary" class="min-h-11"
                                x-on:click="$wire.set('{{ $timeField }}', format(position))">
                                Use <span class="ml-1 font-mono tabular-nums" x-text="format(position)"></span>
                            </x-form-button>
                        </div>
                        <p class="text-xs text-gray-600">Plays the recording from {{ \App\Support\ServiceTimestamp::format($edgeData['clip']['start']) }} to {{ \App\Support\ServiceTimestamp::format($edgeData['clip']['end']) }}. Choose a line to hear it.</p>

                        @if($edgeData['cues'] !== [])
                            <ol class="max-h-56 space-y-1 overflow-y-auto rounded bg-gray-50 p-2 text-sm text-gray-700" aria-label="Transcript around the {{ $edge }}">
                                @foreach($edgeData['cues'] as $cue)
                                    <li>
                                        <button type="button" class="flex min-h-11 w-full items-baseline gap-2 rounded px-2 py-1 text-left hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal"
                                            x-on:click="seek({{ $cue['start'] }})">
                                            <span class="shrink-0 font-mono text-xs tabular-nums text-gray-500">{{ \App\Support\ServiceTimestamp::format($cue['start']) }}</span>
                                            <span class="break-words">{{ $cue['text'] }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-gray-600">No archived recording for this run: the edge cannot be heard here.</p>
                @endif

                <div class="flex flex-wrap items-end gap-2">
                    <div class="w-44">
                        <x-input label="{{ $edgeLabel }} at" wire:model="{{ $timeField }}" placeholder="h:mm:ss.s" dusk="output-edge-time-{{ $section->id }}-{{ $edge }}" />
                    </div>
                    <x-form-button type="button" size="sm" variant="primary" class="min-h-11"
                        wire:click="setOutputEdge({{ $section->id }}, '{{ $edge }}')" dusk="output-edge-set-{{ $section->id }}-{{ $edge }}">
                        {{ $edgeLabel }} here
                    </x-form-button>
                    @if($answer)
                        <x-form-button type="button" size="sm" variant="outline" class="min-h-11"
                            wire:click="clearOutputEdge({{ $section->id }}, '{{ $edge }}')">
                            Clear
                        </x-form-button>
                    @endif
                </div>

                @if($checked)
                    @if($checked['refusal'])
                        <p class="text-sm text-amber-800" role="alert">Refused: {{ $checked['refusal'] }}</p>
                    @elseif($checked['answer'] !== null && $checked['cut'] !== null)
                        <p class="text-sm text-gray-700">Placed: the next cut {{ $edge === 'start' ? 'starts' : 'ends' }} at {{ \App\Support\ServiceTimestamp::format($checked['cut']) }}; the planner alone would use {{ \App\Support\ServiceTimestamp::format((float) $checked['planned']) }}.</p>
                    @elseif($checked['cut'] !== null)
                        <p class="text-sm text-gray-700">The next cut {{ $edge === 'start' ? 'starts' : 'ends' }} at {{ \App\Support\ServiceTimestamp::format($checked['cut']) }}.</p>
                    @endif
                @endif
            </section>
        @endforeach

        <div class="flex flex-wrap items-center gap-3">
            <x-form-button type="button" size="sm" variant="outline" class="min-h-11" wire:click="checkOutputEdges({{ $section->id }})" loading-label="Checking…">
                Check against the plan
            </x-form-button>
            @if(is_array($check) && $check['status'] === 'decode_pending')
                <p class="text-sm text-gray-600" role="status">The edge words are not decoded yet, so the plan cannot be checked. The cut decodes them first, then applies these edges.</p>
            @elseif(is_array($check) && $check['reason'])
                <p class="text-sm text-gray-600" role="status">Plan status: {{ \Illuminate\Support\Str::of($check['reason'])->replace('_', ' ')->ucfirst() }}.</p>
            @endif
        </div>
    </div>
</x-card>
