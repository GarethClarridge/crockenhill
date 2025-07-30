<?php

namespace App\Enums;

enum ProcessingStatus: string
{
  case PENDING = 'pending';
  case PROCESSING = 'processing';
  case COMPLETED = 'completed';
  case FAILED = 'failed';

  public function label(): string
  {
    return match ($this) {
      self::PENDING => 'Pending',
      self::PROCESSING => 'Processing',
      self::COMPLETED => 'Completed',
      self::FAILED => 'Failed',
    };
  }

  public function isComplete(): bool
  {
    return $this === self::COMPLETED;
  }

  public function isFailed(): bool
  {
    return $this === self::FAILED;
  }

  public function isInProgress(): bool
  {
    return $this === self::PROCESSING;
  }

  public function isPending(): bool
  {
    return $this === self::PENDING;
  }
}
