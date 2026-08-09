<?php

declare(strict_types=1);

namespace App\Enums;

enum HistoricImportArtifactKind: string
{
    case SourceSnapshot = 'source_snapshot';
    case Manifest = 'manifest';
    case Plan = 'plan';
    case AssertionBundle = 'assertion_bundle';
    case ProcessingBundle = 'processing_bundle';
    case ConvergenceBundle = 'convergence_bundle';
    case CheckpointReport = 'checkpoint_report';
    case AcceptanceReport = 'acceptance_report';
    case Inventory = 'inventory';
    case Backup = 'backup';
}
