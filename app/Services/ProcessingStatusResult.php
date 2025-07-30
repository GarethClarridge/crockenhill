<?php

namespace App\Services;

use App\Enums\ProcessingStatus;
use Carbon\Carbon;

class ProcessingStatusResult
{
  public function __construct(
    public readonly bool $found,
    public readonly ?string $processingId = null,
    public readonly ?ProcessingStatus $status = null,
    public readonly ?string $currentStep = null,
    public readonly ?string $errorMessage = null,
    public readonly ?int $sermonId = null,
    public readonly ?string $sermonTitle = null,
    public readonly ?string $sermonSlug = null,
    public readonly ?Carbon $createdAt = null,
    public readonly ?Carbon $updatedAt = null,
    public readonly ?string $message = null
  ) {}

  /**
   * Create a result for found processing log
   */
  public static function found(
    string $processingId,
    ProcessingStatus $status,
    ?string $currentStep = null,
    ?string $errorMessage = null,
    ?int $sermonId = null,
    ?string $sermonTitle = null,
    ?string $sermonSlug = null,
    ?Carbon $createdAt = null,
    ?Carbon $updatedAt = null
  ): self {
    return new self(
      found: true,
      processingId: $processingId,
      status: $status,
      currentStep: $currentStep,
      errorMessage: $errorMessage,
      sermonId: $sermonId,
      sermonTitle: $sermonTitle,
      sermonSlug: $sermonSlug,
      createdAt: $createdAt,
      updatedAt: $updatedAt
    );
  }

  /**
   * Create a result for not found processing log
   */
  public static function notFound(): self
  {
    return new self(
      found: false,
      message: 'Processing log not found'
    );
  }

  /**
   * Create a result for error retrieving processing log
   */
  public static function error(string $message): self
  {
    return new self(
      found: false,
      message: $message
    );
  }

  /**
   * Check if processing is complete
   */
  public function isComplete(): bool
  {
    return $this->found && $this->status?->isComplete();
  }

  /**
   * Check if processing has failed
   */
  public function isFailed(): bool
  {
    return $this->found && $this->status?->isFailed();
  }

  /**
   * Check if processing is in progress
   */
  public function isInProgress(): bool
  {
    return $this->found && $this->status?->isInProgress();
  }

  /**
   * Check if processing is pending
   */
  public function isPending(): bool
  {
    return $this->found && $this->status?->isPending();
  }

  /**
   * Get processing duration if available
   */
  public function getDuration(): ?string
  {
    if (!$this->found || !$this->createdAt || !$this->updatedAt) {
      return null;
    }

    return $this->createdAt->diffForHumans($this->updatedAt, true);
  }

  /**
   * Convert to array for API responses
   */
  public function toArray(): array
  {
    if (!$this->found) {
      return [
        'found' => false,
        'message' => $this->message ?? 'Processing log not found',
      ];
    }

    $result = [
      'found' => true,
      'processing_id' => $this->processingId,
      'status' => $this->status->value,
      'status_label' => $this->status->label(),
      'current_step' => $this->currentStep,
      'created_at' => $this->createdAt?->toISOString(),
      'updated_at' => $this->updatedAt?->toISOString(),
      'duration' => $this->getDuration(),
    ];

    if ($this->errorMessage) {
      $result['error_message'] = $this->errorMessage;
    }

    if ($this->sermonId) {
      $result['sermon'] = [
        'id' => $this->sermonId,
        'title' => $this->sermonTitle,
        'slug' => $this->sermonSlug,
        'url' => $this->sermonSlug ? url("/christ/sermons/{$this->sermonSlug}") : null,
      ];
    }

    return $result;
  }
}
