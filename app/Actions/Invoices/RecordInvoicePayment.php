<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;

class RecordInvoicePayment
{
    /**
     * Record a payment against an invoice, then refresh its status from
     * everything paid toward it so far: fully covered becomes Paid,
     * anything less becomes PartiallyPaid.
     */
    public function handle(Invoice $invoice, float $amount, PaymentMethod $method, ?string $referenceNumber = null): Payment
    {
        $payment = $invoice->payments()->create([
            'amount_paid' => $amount,
            'method' => $method,
            'reference_number' => $referenceNumber,
            'paid_at' => now(),
        ]);

        $totalPaid = (float) $invoice->payments()->sum('amount_paid');

        $invoice->update([
            'status' => $totalPaid >= (float) $invoice->total_amount
                ? InvoiceStatus::Paid
                : InvoiceStatus::PartiallyPaid,
        ]);

        return $payment;
    }
}
