<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Gcash = 'gcash';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Gcash => 'GCash',
            self::BankTransfer => 'Bank Transfer',
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
        };
    }
}
