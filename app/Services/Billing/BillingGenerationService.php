<?php

namespace App\Services\Billing;

use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Registration;
use App\Models\RegistrationProgram;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingGenerationService
{
    public function preview(int $registrationId): array
    {
        $registration = Registration::with([
            'billing',
            'child:id,name',
            'registrationPrograms.program',
        ])->findOrFail($registrationId);

        $this->ensureCanGenerate($registration);

        return [
            'registration_id' => $registration->id,
            'registration_number' => $registration->registration_number,
            'child_name' => $registration->child?->name,
            'items' => $this->lineItems($registration->registrationPrograms),
        ];
    }

    public function generate(int $registrationId, array $discounts = []): Billing
    {
        return DB::transaction(function () use ($registrationId, $discounts) {
            $registration = Registration::with([
                'billing',
                'registrationPrograms.program',
            ])->lockForUpdate()->findOrFail($registrationId);

            $this->ensureCanGenerate($registration);

            $discountsByItemId = collect($discounts)->keyBy(
                fn (array $discount) => (int) $discount['registration_program_id']
            );
            $registrationProgramIds = $registration->registrationPrograms
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
            $unknownItemIds = $discountsByItemId->keys()
                ->map(fn ($id) => (int) $id)
                ->diff($registrationProgramIds);

            if ($unknownItemIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'discounts' => 'A discount was supplied for an item that does not belong to this registration.',
                ]);
            }

            $today = now()->format('Ymd');
            $count = Billing::whereDate('created_at', today())->count() + 1;
            $invoiceNumber = 'INV-'.$today.'-'.str_pad($count, 4, '0', STR_PAD_LEFT);

            $billing = Billing::create([
                'registration_id' => $registration->id,
                'invoice_number' => $invoiceNumber,
                'invoice_token' => Str::uuid(),
                'payment_status_id' => 1,
                'total_amount' => 0,
            ]);

            $total = 0;

            foreach ($registration->registrationPrograms as $registrationProgram) {
                $line = $this->lineItem($registrationProgram);
                $discount = $discountsByItemId->get($registrationProgram->id);
                $percentage = (float) ($discount['discount_percentage'] ?? 0);
                $discountAmount = round(
                    $line['subtotal'] * $percentage / 100,
                    0,
                    PHP_ROUND_HALF_UP
                );
                $discountedSubtotal = $line['subtotal'] - $discountAmount;

                BillingItem::create([
                    'billing_id' => $billing->id,
                    'program_id' => $registrationProgram->program_id,
                    'description' => $line['description'],
                    'price' => $line['price'],
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                    'discount_percentage' => $percentage,
                    'discount_amount' => $discountAmount,
                ]);

                $total += $discountedSubtotal;
            }

            $billing->update(['total_amount' => $total]);

            return $billing->fresh(['items']);
        });
    }

    private function ensureCanGenerate(Registration $registration): void
    {
        if ($registration->billing) {
            throw ValidationException::withMessages([
                'registration' => 'Billing already exists.',
            ]);
        }

        if ($registration->registrationPrograms->isEmpty()) {
            throw ValidationException::withMessages([
                'registration' => 'No registration programs found.',
            ]);
        }
    }

    private function lineItems(Collection $registrationPrograms): array
    {
        return $registrationPrograms
            ->map(fn (RegistrationProgram $item) => $this->lineItem($item))
            ->values()
            ->all();
    }

    private function lineItem(RegistrationProgram $item): array
    {
        if (! $item->program) {
            throw ValidationException::withMessages([
                'registration' => 'A registration program is no longer available.',
            ]);
        }

        $quantity = (int) $item->program->session_count === 0
            ? 1
            : (int) $item->learning_period_months;
        $price = (float) $item->price;

        return [
            'registration_program_id' => (int) $item->id,
            'program_id' => (int) $item->program_id,
            'description' => $item->program->name,
            'price' => $price,
            'quantity' => $quantity,
            'subtotal' => round($price * $quantity, 2),
        ];
    }
}
