@extends('layouts.page')

@section('meta_tags')
    @parent
    <x-schema.faq :questions="[
        [
            'question' => 'Can I really get a free Bible?',
            'answer' => 'Yes — completely free, no strings attached. We just ask that you give us your address so we can post or deliver it to you.',
        ],
        [
            'question' => 'Who can request a Bible?',
            'answer' => 'Anyone who lives in or near Crockenhill or Chelsfield in Kent is welcome to request a free Bible.',
        ],
        [
            'question' => 'Which Bible translation will I receive?',
            'answer' => 'We normally give out the New International Version (NIV), which is a widely used modern English translation.',
        ],
        [
            'question' => 'Why are you giving away free Bibles?',
            'answer' => 'We believe the Bible is the most important book ever written, and we want everyone in our community to have the chance to read it for themselves.',
        ],
    ]" />
@stop

@section('dynamic_content')
    <div class="mt-8">
        <livewire:christ.bible-request-form />
    </div>
@stop
