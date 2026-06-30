@extends('layouts.main')

@section('title', 'Component Gallery')

@section('content')
<div class="mx-auto max-w-7xl px-6 py-10 space-y-16">

    {{-- Header --}}
    <div class="border-b border-gray-300 pb-6">
        <h1 class="font-display text-4xl text-cbc-teal">Component Gallery</h1>
        <p class="mt-2 text-gray-600">All shared Blade components rendered with their variants. Local environment only.</p>
        <p class="mt-1 text-sm text-gray-400">Route: <code class="bg-gray-100 px-1 rounded">/dev/components</code></p>
    </div>

    {{-- ─────────────────────────────────────── --}}
    {{-- BRAND COLOURS --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Brand Colours</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
            @foreach([
                ['cbc-teal.light',   '#249a97', 'teal.light'],
                ['cbc-teal',         '#1d686a', 'teal (default)'],
                ['cbc-teal.dark',    '#145557', 'teal.dark'],
                ['cbc-teal.deeper',  '#0f4143', 'teal.deeper'],
                ['cbc-teal.darkest', '#134e4a', 'teal.darkest'],
                ['cbc-crimson',      '#6b0f1a', 'crimson'],
                ['cbc-rose-muted',   '#c07c84', 'rose-muted'],
            ] as [$key, $hex, $label])
            <div>
                <div class="h-14 rounded-lg shadow-sm" style="background: {{ $hex }};"></div>
                <p class="mt-1 text-xs font-mono text-gray-600">{{ $label }}</p>
                <p class="text-xs text-gray-400">{{ $hex }}</p>
            </div>
            @endforeach
        </div>
        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
                <div class="h-14 rounded-lg shadow-sm bg-slate-200 border border-gray-300"></div>
                <p class="mt-1 text-xs text-gray-600">slate-200 (body bg)</p>
            </div>
            <div>
                <div class="h-14 rounded-lg shadow-sm bg-white border border-gray-200"></div>
                <p class="mt-1 text-xs text-gray-600">white (card surface)</p>
            </div>
            <div>
                <div class="h-14 rounded-lg shadow-sm bg-cbc-pattern bg-size-cover"></div>
                <p class="mt-1 text-xs text-gray-600">cbc-pattern (header/footer/buttons)</p>
            </div>
            <div>
                <div class="h-14 rounded-lg shadow-sm bg-[linear-gradient(120deg,#249a97_0%,#1d686a_55%,#145557_100%)]"></div>
                <p class="mt-1 text-xs text-gray-600">teal CTA gradient</p>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- TYPOGRAPHY --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Typography</h2>

        <div class="space-y-8 bg-white rounded-lg p-8 shadow-sm border border-gray-200">
            <div>
                <p class="mb-2 text-xs uppercase tracking-widest text-gray-400">x-h1 — Public page title (font-display text-6xl)</p>
                <x-h1>Sermon Title Goes Here</x-h1>
            </div>
            <div>
                <p class="mb-2 text-xs uppercase tracking-widest text-gray-400">x-h2 — Section heading (gradient text + underline)</p>
                <x-h2>Strengthening Believers</x-h2>
            </div>
            <div>
                <p class="mb-2 text-xs uppercase tracking-widest text-gray-400">x-h3 — Sub-section heading (font-display teal-dark, centred)</p>
                <x-h3>Our Sunday Services</x-h3>
            </div>
            <div>
                <p class="mb-2 text-xs uppercase tracking-widest text-gray-400">Admin heading (font-display text-3xl)</p>
                <h1 class="font-display text-3xl">Manage Sermons</h1>
            </div>
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Prose body copy</p>
                <div class="prose">
                    <p>Crockenhill Baptist Church is an independent evangelical church in Crockenhill, Kent. We meet weekly for worship, Bible teaching, and fellowship. Our mission is to glorify God by worshipping him, strengthening believers, and proclaiming Jesus Christ to our community.</p>
                    <p>This is a second paragraph showing how <a href="#">links</a> appear in prose blocks, alongside <strong>bold text</strong> and <em>emphasis</em>.</p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <p class="mb-2 text-xs uppercase tracking-widest text-gray-400">font-display (Oswald)</p>
                    <p class="font-display text-2xl">ABCDEFGHIJKLM<br>abcdefghijklm<br>0123456789</p>
                </div>
                <div>
                    <p class="mb-2 text-xs uppercase tracking-widest text-gray-400">font-sans (Lato)</p>
                    <p class="font-sans text-2xl">ABCDEFGHIJKLM<br>abcdefghijklm<br>0123456789</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- BUTTONS — x-button (link/nav) --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Buttons — <code class="text-lg font-mono">x-button</code> (links)</h2>

        <div class="space-y-8 bg-white rounded-lg p-8 shadow-sm border border-gray-200">
            {{-- Variants --}}
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Variants (size: md)</p>
                <div class="flex flex-wrap gap-4">
                    <x-button link="#" variant="primary" inline>primary</x-button>
                    <x-button link="#" variant="secondary" inline>secondary</x-button>
                    <x-button link="#" variant="feature" inline>feature</x-button>
                    <x-button link="#" variant="outline" inline>outline</x-button>
                    <x-button link="#" variant="danger" inline>danger</x-button>
                    <x-button link="#" variant="ghost" inline>ghost</x-button>
                </div>
            </div>

            {{-- navigate prop --}}
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Navigate prop — use <code>:navigate="false"</code> for external links or non-SPA destinations</p>
                <div class="flex flex-wrap gap-4">
                    <x-button link="#" variant="outline" inline>Internal (navigate default)</x-button>
                    <x-button link="#" variant="outline" :navigate="false" inline>External / download (:navigate="false")</x-button>
                </div>
            </div>

            {{-- Sizes --}}
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Sizes (variant: primary)</p>
                <div class="flex flex-wrap items-center gap-4">
                    <x-button link="#" variant="primary" size="xs" inline>xs</x-button>
                    <x-button link="#" variant="primary" size="sm" inline>sm</x-button>
                    <x-button link="#" variant="primary" size="md" inline>md</x-button>
                    <x-button link="#" variant="primary" size="lg" inline>lg</x-button>
                    <x-button link="#" variant="primary" size="xl" inline>xl</x-button>
                </div>
            </div>

            {{-- With icons --}}
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">With icons</p>
                <div class="flex flex-wrap gap-4">
                    <x-button link="#" variant="primary" icon="plus" inline>Create</x-button>
                    <x-button link="#" variant="outline" icon="pencil" inline>Edit</x-button>
                    <x-button link="#" variant="danger" icon="trash" inline>Delete</x-button>
                    <x-button link="#" variant="ghost" icon="arrow-left" inline>Back</x-button>
                </div>
            </div>

            {{-- Public CTA pattern --}}
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Public CTA component</p>
                <x-public-cta link="#" label="Join Us This Sunday" />
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- BUTTONS — x-form-button (submit) --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Buttons — <code class="text-lg font-mono">x-form-button</code> (submit)</h2>

        <div class="bg-white rounded-lg p-8 shadow-sm border border-gray-200">
            <div class="space-y-6">
                <div>
                    <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Variants</p>
                    <div class="flex flex-wrap gap-4">
                        <x-form-button variant="primary">Save Changes</x-form-button>
                        <x-form-button variant="secondary">Cancel</x-form-button>
                        <x-form-button variant="outline">Preview</x-form-button>
                        <x-form-button variant="danger">Delete</x-form-button>
                        <x-form-button variant="ghost">Skip</x-form-button>
                    </div>
                </div>
                <div>
                    <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">With icons</p>
                    <div class="flex flex-wrap gap-4">
                        <x-form-button variant="primary" icon="check">Save Changes</x-form-button>
                        <x-form-button variant="danger" icon="trash">Delete Sermon</x-form-button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- FORM CONTROLS --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Form Controls</h2>

        <div class="bg-white rounded-lg p-8 shadow-sm border border-gray-200">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                {{-- Text input --}}
                <div class="space-y-4">
                    <p class="text-xs uppercase tracking-widest text-gray-400">x-input</p>
                    <x-input label="Sermon title" placeholder="Enter sermon title" />
                    <x-input label="Search" placeholder="Search sermons..." icon="magnifying-glass" />
                    <x-input label="With hint" placeholder="Enter value" hint="Used for search and filtering." />
                    <x-input label="With character count" placeholder="Short description" :maxlength="120" />
                    <x-input label="Required field" placeholder="Preacher name" required />
                </div>

                {{-- Select + Textarea + Toggle --}}
                <div class="space-y-4">
                    <p class="text-xs uppercase tracking-widest text-gray-400">x-select</p>
                    <x-select
                        label="Service"
                        placeholder="Choose service..."
                        :options="[['id' => 'morning', 'name' => 'Morning'], ['id' => 'evening', 'name' => 'Evening'], ['id' => 'other', 'name' => 'Other']]"
                    />
                    <x-select
                        label="Service with hint"
                        placeholder="Choose service..."
                        hint="Which service was this recorded at?"
                        :options="[['id' => 'morning', 'name' => 'Morning'], ['id' => 'evening', 'name' => 'Evening']]"
                    />

                    <p class="mt-6 text-xs uppercase tracking-widest text-gray-400">x-toggle</p>
                    <p class="text-sm text-gray-500 italic">(Requires wire:model — shown in structure only)</p>
                    <div class="flex items-center gap-3">
                        <button type="button" role="switch" aria-checked="false"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 bg-gray-200"
                            onclick="this.classList.toggle('bg-green-600'); this.classList.toggle('bg-gray-200'); this.querySelector('span').classList.toggle('translate-x-5')">
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out translate-x-0"></span>
                        </button>
                        <label class="cursor-pointer text-sm font-medium text-gray-700">Published</label>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" role="switch" aria-checked="true"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 bg-green-600">
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out translate-x-5"></span>
                        </button>
                        <label class="cursor-pointer text-sm font-medium text-gray-700">Enabled (on state)</label>
                    </div>
                </div>

                {{-- Textarea --}}
                <div class="lg:col-span-2">
                    <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">x-textarea</p>
                    <x-textarea label="Notes" placeholder="Add any notes about this sermon..." hint="Visible to admins only." rows="4" />
                </div>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- CARDS --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Cards</h2>

        <div class="space-y-6">

            {{-- x-card --}}
            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-card — neutral surface (default). Add <code>prose</code> prop for rich prose content.</p>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <x-card>
                        Card without heading. Default neutral surface — no prose typography applied.
                    </x-card>
                    <x-card heading="Card with Heading">
                        This card has a display-font heading. Used for admin sections and informational panels.
                    </x-card>
                    <x-card prose>
                        <p>This card uses <code>prose</code> typography. Links, <strong>bold text</strong>, and lists receive Tailwind Typography styles. Pass <code>prose</code> on the component to enable.</p>
                    </x-card>
                    <x-card heading="With Footer">
                        Card content goes here.
                        <x-slot:footer>
                            <x-button link="#" variant="outline" size="sm" :navigate="false" inline>View All</x-button>
                        </x-slot:footer>
                    </x-card>
                </div>
            </div>

            {{-- x-clickable-card --}}
            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-clickable-card — public nav card (prop: heading)</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <x-clickable-card link="#" heading="Who is Jesus?">
                        Explore what the Bible says about the person and work of Jesus Christ.
                    </x-clickable-card>
                    <x-clickable-card link="#" heading="Our Beliefs">
                        Read our statement of faith and what we believe as a church.
                    </x-clickable-card>
                    <x-clickable-card link="#" heading="Get Involved">
                        Find out how you can get connected with the church community.
                    </x-clickable-card>
                </div>
            </div>

            {{-- x-page-card (static preview — requires a Page model in real use) --}}
            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-page-card — ministry area card (requires <code>:page="$page"</code> model instance)</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    {{-- Static mock of the card's visual output --}}
                    <div class="group mb-4 flex h-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div class="relative block aspect-video overflow-hidden bg-slate-200">
                            <img class="h-full w-full object-cover" src="/images/headings/small/default.webp" alt="Morning Services" loading="lazy">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/35 via-black/10 to-transparent"></div>
                            <h5 class="absolute inset-x-5 top-1/2 -translate-y-1/2 text-center font-display text-3xl leading-[0.95] text-white [text-shadow:0_2px_8px_rgba(0,0,0,0.45)]">Morning Services</h5>
                        </div>
                        <div class="flex flex-1 items-center justify-center px-6 py-5">
                            <p class="mx-auto max-w-[30ch] text-center text-slate-700">Join us each Sunday morning at 10:30am.</p>
                        </div>
                        <div class="mt-auto flex w-full items-center justify-between gap-3 bg-[linear-gradient(120deg,var(--color-cbc-teal)_0%,var(--color-cbc-teal-dark)_55%,#0e3a3c_100%)] px-6 py-3.5 text-left text-white">
                            <span>Learn about Morning Services</span>
                            <x-heroicon-s-arrow-right-circle class="h-6 w-6 shrink-0 text-white/90" />
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- ADMIN SHELL COMPONENTS --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Admin Shell Components</h2>

        <div class="space-y-10">

            {{-- x-admin.page --}}
            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-admin.page — base title row wrapper (props: title, description; slot: actions)</p>
                <x-admin.page title="Example Page" description="Optional subtitle text goes here.">
                    <x-slot:actions>
                        <x-button link="#" variant="primary" icon="plus" inline>Create</x-button>
                    </x-slot:actions>
                    <p class="text-sm text-gray-500 italic">Default slot content goes here (child components).</p>
                </x-admin.page>
            </div>

            {{-- x-admin.filter-bar --}}
            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-admin.filter-bar — flex filter row wrapper (slot: actions)</p>
                <x-admin.filter-bar>
                    <x-input placeholder="Search..." icon="magnifying-glass" clearable class="w-64" />
                    <x-select placeholder="All Areas"
                        :options="[['id' => 'christ', 'name' => 'Christ'], ['id' => 'church', 'name' => 'Church']]"
                        class="w-40" />

                    <x-slot:actions>
                        <x-form-button variant="ghost" size="sm" icon="x-mark">
                            Clear Filters
                        </x-form-button>
                    </x-slot:actions>
                </x-admin.filter-bar>
            </div>

            {{-- x-admin.list-shell --}}
            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-admin.list-shell — full list page shell (props: title, description; slots: actions, filters, pagination)</p>
                <x-admin.list-shell
                    title="Sermons &amp; Talks"
                    description="Manage sermon recordings and published children's talks."
                >
                    <x-slot:actions>
                        <x-button link="#" variant="primary" icon="cloud-arrow-up" inline>Upload Sermon</x-button>
                    </x-slot:actions>

                    <x-slot:filters>
                        <x-input placeholder="Search..." icon="magnifying-glass" clearable class="w-64" />
                        <x-select placeholder="Service"
                            :options="[['id' => 'morning', 'name' => 'Morning'], ['id' => 'evening', 'name' => 'Evening']]"
                            class="w-40" />
                    </x-slot:filters>

                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3"><p class="font-medium">The Good Shepherd</p></td>
                                <td class="px-4 py-3 text-sm">2 Mar 2025</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex gap-1 justify-end">
                                        <x-button link="#" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </x-admin.list-shell>
            </div>

            {{-- x-admin.form-shell --}}
            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-admin.form-shell — 2/3 + 1/3 form layout (props: title, description, save-action; slots: actions, sidebar)</p>
                <x-admin.form-shell
                    title="Edit Page"
                    description="Update page content and settings."
                >
                    <x-slot:actions>
                        <x-button link="#" variant="outline" inline>Cancel</x-button>
                        <x-form-button variant="primary" icon="check">Save</x-form-button>
                    </x-slot:actions>

                    {{-- Default slot = lg:col-span-2 main area --}}
                    <x-card heading="Page Details">
                        <div class="space-y-4">
                            <x-input label="Heading" placeholder="Page heading" />
                            <x-textarea label="Description" rows="3" placeholder="Brief description..." />
                        </div>
                    </x-card>

                    <x-slot:sidebar>
                        <x-card heading="Settings">
                            <div class="space-y-4">
                                <x-select label="Area"
                                    :options="[['id' => 'church', 'name' => 'Church'], ['id' => 'christ', 'name' => 'Christ']]" />
                                <x-toggle label="Show in Navigation" />
                            </div>
                        </x-card>
                    </x-slot:sidebar>
                </x-admin.form-shell>
            </div>

        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- ADMIN TABLE PATTERN --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Admin Table Pattern</h2>

        <div class="space-y-4">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="font-display text-3xl">Sermons</h1>
                    <p class="text-gray-600">Manage all sermon recordings</p>
                </div>
                <x-button link="#" variant="primary" icon="plus" inline>Add Sermon</x-button>
            </div>

            <div class="flex flex-wrap gap-4">
                <x-input placeholder="Search sermons..." icon="magnifying-glass" class="w-64" />
                <x-select placeholder="All services" :options="[['id' => 'morning', 'name' => 'Morning'], ['id' => 'evening', 'name' => 'Evening']]" class="w-48" />
            </div>

            <x-card>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Preacher</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach([
                                ['The Good Shepherd', 'David Jones', 'Morning', '2 Mar 2025'],
                                ['Trusting in God', 'Mark Smith', 'Evening', '23 Feb 2025'],
                                ['The Lord is My Light', 'David Jones', 'Morning', '16 Feb 2025'],
                            ] as [$title, $preacher, $service, $date])
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $title }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $preacher }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $service }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $date }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <div class="flex justify-end gap-2">
                                        <x-button link="#" variant="ghost" size="xs" icon="pencil" inline>Edit</x-button>
                                        <x-button link="#" variant="danger" size="xs" icon="trash" inline>Delete</x-button>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- LAYOUT COMPONENTS --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Layout Components</h2>

        <div class="space-y-6">
            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-content-wrapper — prose rail (default max-w-2xl xl:max-w-3xl)</p>
                <div class="bg-white rounded-lg p-4 border border-gray-200">
                    <x-content-wrapper>
                        <div class="prose">
                            <p>This paragraph is inside <code>x-content-wrapper</code>. It constrains content to a comfortable reading width. The wrapper accepts an optional <code>wide</code> prop to expand to card-grid width.</p>
                        </div>
                    </x-content-wrapper>
                </div>
            </div>

            <div>
                <p class="mb-3 text-xs uppercase tracking-widest text-gray-400">x-breadcrumbs (props: area, heading — auto-builds trail from request path)</p>
                <x-breadcrumbs area="christ" heading="The Good Shepherd" />
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- SESSION MESSAGES --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Session Messages</h2>
        <div class="space-y-3">
            <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                <strong>Success:</strong> Sermon has been saved successfully.
            </div>
            <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800" role="alert">
                <strong>Error:</strong> There was a problem saving the sermon. Please try again.
            </div>
            <div class="rounded-md bg-yellow-50 border border-yellow-200 p-4 text-sm text-yellow-800">
                <strong>Warning:</strong> This action cannot be undone.
            </div>
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-800">
                <strong>Info:</strong> Processing may take a few minutes.
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- ALERT — x-alert --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Alert — <code class="text-lg font-mono">x-alert</code></h2>

        <div class="space-y-4">
            <p class="text-xs uppercase tracking-widest text-gray-400">Props: type (info|success|warning|error), title, dismissible</p>
            <x-alert type="success">Sermon has been saved successfully.</x-alert>
            <x-alert type="error">There was a problem saving the sermon. Please try again.</x-alert>
            <x-alert type="warning">This action cannot be undone.</x-alert>
            <x-alert type="info">Processing may take a few minutes.</x-alert>
            <x-alert type="success" title="Saved">Your changes have been applied and the sermon is now published.</x-alert>
            <x-alert type="warning" title="Review required" dismissible>One or more segments could not be automatically identified. Please review the timestamps below.</x-alert>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- METADATA LIST — x-metadata-list --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Metadata List — <code class="text-lg font-mono">x-metadata-list</code></h2>

        <div class="space-y-6">
            <p class="text-xs uppercase tracking-widest text-gray-400">Props: items (array of [label, value]), stacked (bool — label above value vs inline), columns (1–4 — switches to a responsive grid when &gt; 1)</p>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <x-card heading="Inline (default) — sidebar panels">
                    <x-metadata-list :items="[
                        ['label' => 'Status',   'value' => 'Completed'],
                        ['label' => 'Reason',   'value' => 'Audio quality acceptable'],
                        ['label' => 'Assessed', 'value' => '15 May 2026 09:32'],
                        ['label' => 'Override', 'value' => 'Default (automatic)'],
                    ]" />
                </x-card>

                <x-card heading="Stacked — label above value">
                    <x-metadata-list stacked :items="[
                        ['label' => 'Alternate title', 'value' => 'What Does the Bible Say?'],
                        ['label' => 'CCLI',            'value' => '1234567'],
                        ['label' => 'Verse order',     'value' => 'v1 c v2 c b c'],
                        ['label' => 'Usage count',     'value' => '12'],
                        ['label' => 'Last used',       'value' => '5 Jan 2026'],
                    ]" class="space-y-3" />
                </x-card>
            </div>

            <x-card heading="3-column grid — timeline / inline groups">
                <x-metadata-list columns="3" :items="[
                    ['label' => 'Started',   'value' => '15 May 2026 09:32:10'],
                    ['label' => 'Completed', 'value' => '15 May 2026 09:33:42'],
                    ['label' => 'Duration',  'value' => '1m 32s'],
                ]" />
            </x-card>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- BADGE — x-badge --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Badge — <code class="text-lg font-mono">x-badge</code></h2>

        <div class="bg-white rounded-lg p-8 shadow-sm border border-gray-200 space-y-6">
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Variants (size: sm)</p>
                <div class="flex flex-wrap gap-3">
                    <x-badge>default</x-badge>
                    <x-badge variant="success">success</x-badge>
                    <x-badge variant="warning">warning</x-badge>
                    <x-badge variant="danger">danger</x-badge>
                    <x-badge variant="info">info</x-badge>
                    <x-badge variant="teal">teal</x-badge>
                </div>
            </div>
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Sizes</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-badge size="xs" variant="success">xs published</x-badge>
                    <x-badge size="sm" variant="info">sm morning</x-badge>
                    <x-badge size="md" variant="teal">md crockenhill</x-badge>
                </div>
            </div>
            <div>
                <p class="mb-4 text-xs uppercase tracking-widest text-gray-400">Example in-context (table cell)</p>
                <div class="flex flex-wrap gap-3">
                    <x-badge variant="success">Published</x-badge>
                    <x-badge variant="warning">Draft</x-badge>
                    <x-badge variant="info">Morning</x-badge>
                    <x-badge variant="info">Evening</x-badge>
                    <x-badge variant="danger">Needs Review</x-badge>
                </div>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- CHECKBOX — x-checkbox --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Checkbox — <code class="text-lg font-mono">x-checkbox</code></h2>

        <div class="bg-white rounded-lg p-8 shadow-sm border border-gray-200">
            <div class="space-y-4">
                <p class="text-xs uppercase tracking-widest text-gray-400">Props: label, hint. Accepts name, wire:model, checked.</p>
                <x-checkbox label="Published" name="published" />
                <x-checkbox label="Show in navigation" hint="Displays this page in the main site navigation." name="navigation" />
                <x-checkbox label="Send notification email" name="notify" checked />
                <x-checkbox label="I agree to the terms" name="agree" />
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- TOGGLE — x-toggle (updated) --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Toggle — <code class="text-lg font-mono">x-toggle</code></h2>

        <div class="bg-white rounded-lg p-8 shadow-sm border border-gray-200">
            <div class="space-y-4">
                <p class="text-xs uppercase tracking-widest text-gray-400">Livewire mode: <code>wire:model="..."</code> — Plain Blade mode: <code>name="..." :checked="true/false"</code></p>
                <p class="text-sm text-gray-500 italic">Livewire mode requires a live component — plain Blade examples are shown below.</p>
                <x-toggle label="Plain Blade toggle (off)" name="show_notes" />
                <x-toggle label="Plain Blade toggle (on)" name="featured" :checked="true" />
                <x-toggle label="With hint" name="navigation" hint="Displays in the main navigation menu." />
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- SPACING CHEATSHEET --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Spacing Cheatsheet</h2>
        <div class="bg-white rounded-lg p-8 shadow-sm border border-gray-200 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left pb-3 font-medium text-gray-700">Context</th>
                        <th class="text-left pb-3 font-medium text-gray-700">Class</th>
                        <th class="text-left pb-3 font-medium text-gray-700">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach([
                        ['Section top spacing', 'mt-6 or mt-8', '1.5rem / 2rem'],
                        ['Card internal padding', 'p-6', '1.5rem'],
                        ['Form field vertical rhythm', 'space-y-4', '1rem gap'],
                        ['Sibling spacing', 'gap-*', 'prefer gap over margin'],
                        ['Standard content rail', 'max-w-2xl xl:max-w-3xl', '42rem / 48rem'],
                        ['Card grid max width', 'lg:max-w-5xl xl:max-w-7xl', '64rem / 80rem'],
                        ['Card grid columns', 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', 'responsive'],
                        ['Admin form layout', 'grid-cols-1 lg:grid-cols-3', 'main = lg:col-span-2'],
                    ] as [$ctx, $class, $value])
                    <tr>
                        <td class="py-2 text-gray-600">{{ $ctx }}</td>
                        <td class="py-2 font-mono text-xs text-cbc-teal">{{ $class }}</td>
                        <td class="py-2 text-gray-500">{{ $value }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- ADMIN PIPELINE STEPS — x-admin.pipeline-steps --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Pipeline Steps — <code class="text-lg font-mono">x-admin.pipeline-steps</code></h2>

        <div class="space-y-4">
            <p class="text-xs uppercase tracking-widest text-gray-400">Props: steps (list of [label, state: done|active|blocked|todo]). Used on the services hub hero and the service workbench header.</p>

            <x-card heading="Run in progress">
                <x-admin.pipeline-steps :steps="[
                    ['label' => 'Plan', 'state' => 'done'],
                    ['label' => 'Recording', 'state' => 'done'],
                    ['label' => 'Processed', 'state' => 'active'],
                    ['label' => 'Review', 'state' => 'todo'],
                    ['label' => 'Published', 'state' => 'todo'],
                ]" />
            </x-card>

            <x-card heading="Paused on a human (amber = needs review)">
                <x-admin.pipeline-steps :steps="[
                    ['label' => 'Plan', 'state' => 'done'],
                    ['label' => 'Recording', 'state' => 'done'],
                    ['label' => 'Processed', 'state' => 'done'],
                    ['label' => 'Review', 'state' => 'blocked'],
                    ['label' => 'Published', 'state' => 'todo'],
                ]" />
            </x-card>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- ADMIN ATTENTION STRIP — x-admin.attention-strip --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Attention Strip — <code class="text-lg font-mono">x-admin.attention-strip</code></h2>

        <div class="space-y-4">
            <p class="text-xs uppercase tracking-widest text-gray-400">Props: chips (list of [label, count, href]) — zero-count chips are omitted; an empty set renders the quiet all-caught-up line.</p>

            <x-card heading="Work waiting">
                <x-admin.attention-strip :chips="[
                    ['label' => 'Emails', 'count' => 2, 'href' => '#'],
                    ['label' => 'Sections', 'count' => 5, 'href' => '#'],
                    ['label' => 'Segments', 'count' => 0, 'href' => '#'],
                    ['label' => 'Services', 'count' => 1, 'href' => '#'],
                ]" />
            </x-card>

            <x-card heading="All caught up">
                <x-admin.attention-strip :chips="[
                    ['label' => 'Emails', 'count' => 0, 'href' => '#'],
                ]" />
            </x-card>
        </div>
    </section>

    {{-- ─────────────────────────────────────── --}}
    {{-- ADMIN ACTION MENU — x-admin.action-menu --}}
    {{-- ─────────────────────────────────────── --}}
    <section>
        <h2 class="mb-6 font-display text-2xl text-gray-700 border-b border-gray-200 pb-2">Action Menu — <code class="text-lg font-mono">x-admin.action-menu</code></h2>

        <div class="space-y-4">
            <p class="text-xs uppercase tracking-widest text-gray-400">Props: label (default "Add"), icon (default "plus"); items via x-admin.action-menu-item (link, icon). Keyboard-operable Alpine dropdown — the hub's single + Add entry point.</p>

            <x-card>
                <x-admin.action-menu label="Add">
                    <x-admin.action-menu-item link="#" icon="film">Upload recording</x-admin.action-menu-item>
                    <x-admin.action-menu-item link="#" icon="arrow-up-tray">Upload order of service</x-admin.action-menu-item>
                    <x-admin.action-menu-item link="#" icon="envelope">Paste email text</x-admin.action-menu-item>
                    <x-admin.action-menu-item link="#" icon="pencil-square">Create manually</x-admin.action-menu-item>
                </x-admin.action-menu>
            </x-card>
        </div>
    </section>

    {{-- Footer --}}
    <div class="border-t border-gray-300 pt-6 text-center text-xs text-gray-400">
        Component gallery — local environment only — <code>/dev/components</code>
    </div>

</div>
@endsection
