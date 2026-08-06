<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingReportController extends Controller
{
    private function forbidNonAdmin(): void
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

    public function index(Request $request)
    {
        $this->forbidNonAdmin();

        // ======================
        // VALIDATION
        // ======================

        $validated = $request->validate([
            'start_month' => [
                'required',
                'integer',
                'between:1,12',
            ],

            'end_month' => [
                'required',
                'integer',
                'between:1,12',
            ],

            'year' => [
                'required',
                'integer',
                'between:2000,2100',
            ],

            'registration_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'invoice_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'child_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'program_category_id' => [
                'nullable',
                'integer',
                'exists:program_categories,id',
            ],

            'payment_status' => [
                'nullable',
                Rule::in([
                    'Unpaid',
                    'Waiting',
                    'Paid',
                ]),
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        // ======================
        // PERIOD VALIDATION
        // ======================

        if (
            $validated['start_month'] >
            $validated['end_month']
        ) {
            return response()->json([
                'message' => 'Start month cannot be later than end month.',
            ], 422);
        }

        $startDate = Carbon::create(
            $validated['year'],
            $validated['start_month'],
            1
        )->startOfMonth();

        $endDate = Carbon::create(
            $validated['year'],
            $validated['end_month'],
            1
        )->endOfMonth();

        // ======================
        // BASE PERIOD QUERY
        // ======================

        $periodQuery = Billing::query()
            ->whereBetween(
                'billings.created_at',
                [
                    $startDate,
                    $endDate,
                ]
            );

        // ======================
        // SUMMARY
        // PERIOD FILTER ONLY
        // ======================

        $summary = [
            'total_billing' => (float) (clone $periodQuery)
                ->sum('total_amount'),

            'total_paid' => (float) (clone $periodQuery)
                ->where(
                    'payment_status_id',
                    3
                )
                ->sum('total_amount'),

            /*
             * Unpaid + Waiting.
             * Waiting has not been approved yet.
             */
            'total_unpaid' => (float) (clone $periodQuery)
                ->whereIn(
                    'payment_status_id',
                    [1, 2]
                )
                ->sum('total_amount'),

            'total_registrations' => (clone $periodQuery)
                ->distinct()
                ->count('registration_id'),
        ];

        // ======================
        // TABLE QUERY
        // ======================

        $query = Billing::with([
            'registration:id,registration_number,child_id,program_category_id',

            'registration.child:id,name',

            'registration.programCategory:id,name',

            'paymentStatus:id,name',
        ])
            ->whereBetween(
                'billings.created_at',
                [
                    $startDate,
                    $endDate,
                ]
            );

        // ======================
        // TABLE FILTERS
        // ======================

        $registrationNumber = trim(
            $validated['registration_number'] ?? ''
        );

        if ($registrationNumber !== '') {
            $query->whereHas(
                'registration',
                function (
                    Builder $registrationQuery
                ) use ($registrationNumber) {
                    $registrationQuery->where(
                        'registration_number',
                        'like',
                        '%'.$registrationNumber.'%'
                    );
                }
            );
        }

        $invoiceNumber = trim(
            $validated['invoice_number'] ?? ''
        );

        if ($invoiceNumber !== '') {
            $query->where(
                'invoice_number',
                'like',
                '%'.$invoiceNumber.'%'
            );
        }

        $childName = trim(
            $validated['child_name'] ?? ''
        );

        if ($childName !== '') {
            $query->whereHas(
                'registration.child',
                function (
                    Builder $childQuery
                ) use ($childName) {
                    $childQuery->where(
                        'name',
                        'like',
                        '%'.$childName.'%'
                    );
                }
            );
        }

        if (
            ! empty(
                $validated['program_category_id']
            )
        ) {
            $query->whereHas(
                'registration',
                function (
                    Builder $registrationQuery
                ) use ($validated) {
                    $registrationQuery->where(
                        'program_category_id',
                        $validated[
                            'program_category_id'
                        ]
                    );
                }
            );
        }

        if (
            ! empty(
                $validated['payment_status']
            )
        ) {
            $query->whereHas(
                'paymentStatus',
                function (
                    Builder $statusQuery
                ) use ($validated) {
                    $statusQuery->where(
                        'name',
                        $validated[
                            'payment_status'
                        ]
                    );
                }
            );
        }

        // ======================
        // PAGINATION
        // ======================

        $perPage = min(
            max(
                (int) (
                    $validated['per_page'] ??
                    10
                ),
                1
            ),
            50
        );

        $billings = $query
            ->orderByDesc(
                'billings.created_at'
            )
            ->orderByDesc(
                'billings.id'
            )
            ->paginate($perPage);

        // ======================
        // RESPONSE TRANSFORM
        // ======================

        $billings
            ->getCollection()
            ->transform(
                function (Billing $billing) {
                    return [
                        'id' => $billing->id,

                        'registration_number' => $billing
                            ->registration
                            ?->registration_number
                            ?? '-',

                        'invoice_number' => $billing
                            ->invoice_number,

                        'child_name' => $billing
                            ->registration
                            ?->child
                            ?->name
                            ?? '-',

                        'program_category' => $billing
                            ->registration
                            ?->programCategory
                            ?->name
                            ?? '-',

                        'total_billing' => (float) $billing
                            ->total_amount,

                        'payment_status' => $billing
                            ->paymentStatus
                            ?->name
                            ?? '-',
                    ];
                }
            );

        return response()->json([
            'summary' => $summary,

            'data' => $billings->items(),

            'current_page' => $billings->currentPage(),

            'last_page' => $billings->lastPage(),

            'per_page' => $billings->perPage(),

            'total' => $billings->total(),
        ]);
    }
}
