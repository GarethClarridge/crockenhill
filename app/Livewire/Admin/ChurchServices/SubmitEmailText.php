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
            'from' => ['nullable', ...array_filter($modelRules['from'], fn ($rule) => $rule !== 'required')],
            'subject' => ['nullable', ...array_filter($modelRules['subject'], fn ($rule) => $rule !== 'required')],
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
            'bodyPlain.min' => 'Please paste at least 20 characters of email text.',
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
            ->layout('layouts.admin', ['title' => 'Paste Email Text', 'heading' => 'Paste Email Text']);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
