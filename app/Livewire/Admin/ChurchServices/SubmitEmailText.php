<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\InboundEmailStatus;
use App\Jobs\ProcessInboundOosEmail;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Models\InboundEmail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class SubmitEmailText extends Component
{
    use WithAdminAuthorization;

    public string $from = '';

    public string $subject = '';

    public string $bodyPlain = '';

    public bool $submitted = false;

    public function mount(): void
    {
        $this->abortIfDisabled();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $modelRules = InboundEmail::validationRules();

        return [
            'from' => ['nullable', ...$modelRules['from']],
            'subject' => ['nullable', ...$modelRules['subject']],
            'bodyPlain' => ['required', 'string', 'min:20', 'max:50000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'bodyPlain.required' => 'Please paste the email body text.',
            'bodyPlain.min' => 'The email body must be at least 20 characters.',
            'bodyPlain.max' => 'The email body must not exceed 50,000 characters.',
        ];
    }

    public function submit(): void
    {

        $this->authorizeAdmin();

        $this->validate();

        $syntheticId = 'manual-'.Str::uuid().'@admin.crockenhill.org';

        $inboundEmail = InboundEmail::query()->create([
            'message_id' => $syntheticId,
            'from' => $this->from ?: 'admin@manual-entry',
            'subject' => $this->subject ?: 'Manual entry',
            'body_plain' => $this->bodyPlain,
            'body_html' => null,
            'received_at' => now(),
            'status' => InboundEmailStatus::Pending,
        ]);

        ProcessInboundOosEmail::dispatch($inboundEmail);

        $this->submitted = true;
    }

    public function render(): View
    {
        return view('livewire.admin.church-services.submit-email-text')
            ->layout('layouts.admin', ['title' => 'Submit Email Text', 'heading' => 'Submit Email Text']);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
