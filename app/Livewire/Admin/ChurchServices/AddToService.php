<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\InboundEmailStatus;
use App\Jobs\ProcessInboundOosEmail;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class AddToService extends Component
{
    use WithAdminAuthorization;
    use WithFileUploads;
    use WithNotifications;

    #[Url(except: 'plan')]
    public string $intent = 'plan';

    #[Url(except: null)]
    public ?int $churchServiceId = null;

    /** @var TemporaryUploadedFile|null */
    public mixed $file = null;

    public string $from = '';

    public string $subject = '';

    public string $bodyPlain = '';

    public bool $submitted = false;

    public function mount(): void
    {
        $this->normaliseIntent();
    }

    public function importPlan(): void
    {
        $this->authorizeAdmin();
        $this->resetErrorBag();

        $hasFile = $this->file !== null;
        $hasEmailText = filled($this->bodyPlain);

        if ($hasFile === $hasEmailText) {
            $message = $hasFile
                ? 'Choose either an OpenLP file or pasted email text, not both.'
                : 'Upload an OpenLP file or paste the email text.';

            $this->addError('planInput', $message);

            return;
        }

        if ($hasFile) {
            $this->importOpenLpFile();

            return;
        }

        $this->importEmailText();
    }

    public function resetEmailForm(): void
    {
        $this->authorizeAdmin();

        $this->reset(['from', 'subject', 'bodyPlain', 'submitted']);
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $recentServices = ChurchService::query()
            ->select(['id', 'date', 'service', 'original_filename', 'updated_at'])
            ->withCount('items')
            ->orderByDesc('date')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        return view('livewire.admin.church-services.add-to-service', [
            'recentServices' => $recentServices,
        ])->layout('layouts.admin', [
            'title' => 'Add to service',
            'heading' => 'Add to service',
        ]);
    }

    private function normaliseIntent(): void
    {
        if (in_array($this->intent, ['plan', 'recording'], true)) {
            return;
        }

        $this->intent = 'plan';
    }

    private function importOpenLpFile(): void
    {
        $maxSize = (int) config('service-tracking.upload.max_size_kb', 614400);
        $validated = $this->validate(
            ['file' => ['required', 'file', 'mimes:zip', 'max:'.$maxSize]],
            $this->fileValidationMessages(),
        );
        $uploadedFile = $validated['file'] ?? null;

        if (! $uploadedFile instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'Please upload a valid OpenLP .osz file.',
            ]);
        }

        try {
            $result = app(ImportChurchServiceFromOpenLp::class)->import($uploadedFile);

            $this->file = null;
            $this->success('Service imported successfully', redirectTo: route('admin.services.show', $result->churchService));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('file', 'Unable to import this file. Please verify it is a valid OpenLP archive.');
        }
    }

    private function importEmailText(): void
    {
        $modelRules = InboundEmail::validationRules();

        $this->validate([
            'from' => ['nullable', ...array_filter($modelRules['from'], fn ($rule) => $rule !== 'required')],
            'subject' => ['nullable', ...array_filter($modelRules['subject'], fn ($rule) => $rule !== 'required')],
            'bodyPlain' => ['required', 'string', 'min:20', 'max:50000'],
        ], [
            'bodyPlain.required' => 'Please paste the email body text.',
            'bodyPlain.min' => 'The email body must be at least 20 characters.',
            'bodyPlain.max' => 'The email body must not exceed 50,000 characters.',
        ]);

        $inboundEmail = InboundEmail::query()->create([
            'message_id' => 'manual-'.Str::uuid().'@admin.crockenhill.org',
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

    /** @return array<string, string> */
    private function fileValidationMessages(): array
    {
        $maxSize = round(((int) config('service-tracking.upload.max_size_kb', 614400)) / 1024);

        return [
            'file.required' => 'Please upload an OpenLP .osz file.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The uploaded file must be a valid OpenLP .osz archive.',
            'file.max' => "The uploaded file is too large. It must not exceed {$maxSize} MB.",
        ];
    }
}
