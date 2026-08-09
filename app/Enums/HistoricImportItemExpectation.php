<?php

declare(strict_types=1);

namespace App\Enums;

enum HistoricImportItemExpectation: string
{
    case Process = 'process';
    case Exclude = 'exclude';
}
