<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">
            {{ $churchService->date->format('j M Y') }} ({{ $churchService->service->label() }})
        </h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                Back to Services
            </x-button>
            <x-button link="{{ route('admin.services.upload') }}" variant="primary" icon="arrow-up-tray" inline>
                Upload Another
            </x-button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card heading="Service Details">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-gray-500">Date</p>
                        <p class="font-medium">{{ $churchService->date->format('l j F Y') }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Service</p>
                        <p class="font-medium">{{ $churchService->service->label() }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Source</p>
                        <p class="font-medium uppercase">{{ $churchService->source }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Original filename</p>
                        <p class="font-medium">{{ $churchService->original_filename ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Imported at</p>
                        <p class="font-medium">{{ $churchService->updated_at?->format('j M Y H:i') }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Review status</p>
                        @if($churchService->needs_review)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                                Needs review
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                Ready
                            </span>
                        @endif
                    </div>
                </div>
            </x-card>

            <x-card heading="Service Items">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pos</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">OpenLP Match Key</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($churchService->items as $item)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium">{{ $item->position }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($item->type) {
                                            'songs' => 'bg-green-100 text-green-800',
                                            'bibles' => 'bg-blue-100 text-blue-800',
                                            'presentations' => 'bg-slate-100 text-slate-800',
                                            default => 'bg-gray-100 text-gray-800',
                                        } }}">
                                            {{ ucfirst($item->type) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-sm font-medium">{{ $item->title }}</p>
                                        @if($item->source_title && $item->source_title !== $item->title)
                                            <p class="text-xs text-gray-500">Source: {{ $item->source_title }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600">
                                        {{ $item->openlp_search_title ?: '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                        This service has no items.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card heading="Import Metadata">
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-gray-500">Parse method</p>
                        <p class="font-medium">{{ $importMetadata['parse_method'] ?? 'unknown' }}</p>
                    </div>

                    <div>
                        <p class="text-gray-500">Confidence score</p>
                        <p class="font-medium">
                            @if($confidenceScore !== null)
                                {{ number_format($confidenceScore * 100, 0) }}%
                            @else
                                Unknown
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="text-gray-500">Filename mismatch</p>
                        <p class="font-medium">{{ ($importMetadata['filename_mismatch'] ?? false) ? 'Yes' : 'No' }}</p>
                    </div>
                </div>
            </x-card>

            @if($warnings !== [])
                <x-card heading="Warnings">
                    <div class="space-y-2">
                        @foreach($warnings as $warning)
                            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                {{ $warning }}
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
