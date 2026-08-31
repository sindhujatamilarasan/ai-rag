<?php

namespace App\Enums;

enum DetectionStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';

    /** A run that will not change again. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
