<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Services\Billing\BillingGenerationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BillingController extends Controller
{
    private function forbidNonAdmin()
    {
        if (
            auth()->user()->role !==
            'admin'
        ) {

            abort(
                403,
                'Forbidden'
            );

        }
    }

    public function show($id)
    {
        $billing = Billing::with([

            'items',

            'registration.child',

            'registration.payer',

            'paymentStatus',

        ])->findOrFail($id);

        return response()->json([

            'data' => $billing,

        ]);
    }

    public function previewGeneration($id, BillingGenerationService $billingGenerationService)
    {
        $this->forbidNonAdmin();

        return response()->json([
            'data' => $billingGenerationService->preview((int) $id),
        ]);
    }

    public function generateBilling(Request $request, $id, BillingGenerationService $billingGenerationService)
    {
        $this->forbidNonAdmin();

        $validated = $request->validate([
            'discounts' => ['sometimes', 'array'],
            'discounts.*.registration_program_id' => ['required', 'integer', 'distinct'],
            'discounts.*.discount_percentage' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'regex:/^\\d{1,3}(\\.\\d{1,2})?$/',
            ],
        ]);

        $billing = $billingGenerationService->generate(
            (int) $id,
            $validated['discounts'] ?? []
        );

        return response()->json([
            'message' => 'Billing generated successfully.',
            'data' => $billing,
        ]);
    }

    public function cancel($id)
    {
        $this->forbidNonAdmin();

        return DB::transaction(function () use ($id) {

            $billing = Billing::with('items')
                ->findOrFail($id);

            if ($billing->payment_status_id != 1) {

                return response()->json([
                    'message' => 'Only unpaid billing can be cancelled.',
                ], 422);
            }

            $billing->items()->delete();

            $billing->delete();

            return response()->json([
                'message' => 'Billing cancelled successfully.',
            ]);
        });
    }

    public function publicShowByToken($token)
    {
        $billing = Billing::with([

            'items',

            'registration.child',

            'registration.payer',

            'paymentStatus',

        ])
            ->where(
                'invoice_token',
                $token
            )
            ->firstOrFail();

        return response()->json([

            'data' => $billing,

        ]);
    }

    public function uploadReceiptByToken(Request $request, $token)
    {
        return DB::transaction(function () use ($request, $token) {

            $billing = Billing::where(
                'invoice_token',
                $token
            )->firstOrFail();

            // ======================
            // ONLY UNPAID
            // ======================

            if ($billing->payment_status_id != 1) {

                return response()->json([
                    'message' => 'Receipt cannot be uploaded.',
                ], 422);
            }

            // ======================
            // VALIDATION
            // ======================

            $request->validate([
                'receipt' => [
                    'required',
                    'image',
                    'mimes:jpg,jpeg,png',
                    'max:5120', // 5 MB
                ],
            ]);

            // ======================
            // DELETE OLD FILE
            // ======================

            if ($billing->payment_receipt) {

                Storage::delete($billing->payment_receipt);
            }

            // ======================
            // STORE FILE
            // ======================

            $path = $request
                ->file('receipt')
                ->store(
                    'montessori/payment-receipts',
                );

            // ======================
            // UPDATE BILLING
            // ======================

            $billing->update([

                'payment_receipt' => $path,

                'payment_status_id' => 2, // Waiting

                'admin_note' => null,

            ]);

            return response()->json([

                'message' => 'Receipt uploaded successfully.',

            ]);
        });
    }

    public function approve($id)
    {
        $this->forbidNonAdmin();

        $billing = Billing::findOrFail($id);

        if ($billing->payment_status_id != 2) {

            return response()->json([
                'message' => 'Only waiting billing can be approved.',
            ], 422);
        }

        $billing->update([

            'payment_status_id' => 3, // Paid

            'admin_note' => null,

        ]);

        return response()->json([

            'message' => 'Payment approved successfully.',

        ]);
    }

    public function reject(Request $request, $id)
    {
        $this->forbidNonAdmin();

        $request->validate([

            'admin_note' => [
                'required',
                'string',
                'max:1000',
            ],

        ]);

        $billing = Billing::findOrFail($id);

        if ($billing->payment_status_id != 2) {

            return response()->json([
                'message' => 'Only waiting billing can be rejected.',
            ], 422);
        }

        if ($billing->payment_receipt) {

            Storage::delete($billing->payment_receipt);
        }

        $billing->update([

            'payment_status_id' => 1, // Unpaid

            'admin_note' => $request->admin_note,

            'payment_receipt' => null,

        ]);

        return response()->json([

            'message' => 'Payment rejected successfully.',

        ]);
    }

    public function downloadPdf($id)
    {
        $billing = Billing::with([
            'items',
            'registration.child',
            'registration.payer',
            'paymentStatus',
        ])->findOrFail($id);

        $downloadedBy = auth()->user();

        $generatedAt = now()->locale('id');

        $pdf = Pdf::loadView(
            'pdf.invoice',
            compact(
                'billing',
                'downloadedBy',
                'generatedAt'
            )
        );

        return $pdf->download(
            "{$billing->invoice_number}.pdf"
        );
    }
}
