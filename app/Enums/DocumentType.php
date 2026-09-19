<?php

namespace App\Enums;

enum DocumentType: string
{
    case Contract = 'contract';
    case ValidId = 'valid_id';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Contract',
            self::ValidId => 'Valid ID',
            self::Other => 'Other',
        };
    }
}
