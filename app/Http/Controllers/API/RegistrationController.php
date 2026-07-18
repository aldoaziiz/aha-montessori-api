<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationResource;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Child;
use App\Models\Clinic;
use App\Models\Guardian;
use App\Models\GuardianRole;
use App\Models\Payer;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Registration;
use App\Models\RegistrationProgram;
use App\Models\User;
use App\Services\Auth\CreateGuardianUserService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
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

    private function forbidTherapist()
    {
        if (
            auth()->user()->role ===
            'therapist'
        ) {

            abort(
                403,
                'Forbidden'
            );

        }
    }

    public function index(Request $request)
    {
        $query = Registration::with([
            'child.guardians',
            'programs',
            'billing.paymentStatus',
        ]);

        $query->leftJoin(
            'children',
            'registrations.child_id',
            '=',
            'children.id'
        );

        $query->leftJoin(
            'billings',
            'registrations.id',
            '=',
            'billings.registration_id'
        );

        $query->select('registrations.*');

        // ======================
        // SORTING
        // ======================

        $sortBy = $request->sort_by;
        $sortOrder = $request->sort_order ?? 'asc';

        switch ($sortBy) {

            case 'child':

                $query->orderBy(
                    'children.name',
                    $sortOrder
                );

                break;

            case 'payment_status.id':

                $query->orderBy(
                    'billings.payment_status_id',
                    $sortOrder
                );

                break;

            case 'registration_number':

                $query->orderBy(
                    'registrations.registration_number',
                    $sortOrder
                );

                break;

            case 'created_at':

                $query->orderBy(
                    'registrations.created_at',
                    $sortOrder
                );

                break;

            default:

                $query->orderBy(
                    'registrations.created_at',
                    'desc'
                );
        }

        // SEARCH
        if ($request->search) {
            $query->whereHas('child', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%');
            });
        }

        // PAGINATION
        $data = $query->paginate($request->per_page ?? 10);

        // 🔥 TRANSFORM GUARDIAN ROLE
        $data->getCollection()->transform(function ($registration) {

            if ($registration->child && $registration->child->guardians) {

                $registration->child->guardians->transform(function ($guardian) {

                    $guardian->guardian_role = GuardianRole::find(
                        $guardian->pivot->guardian_role_id
                    );

                    return $guardian;
                });
            }

            return $registration;
        });

        return response()->json($data);
    }

    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {

            // ======================
            // VALIDATION
            // ======================

            $rules = [];

            if (
                ! isset(
                    $request->guardian['id']
                )
            ) {

                $rules['guardian.email'] = [

                    'required',

                    'email',

                ];
            }

            $request->validate(
                $rules
            );

            // ======================
            // CHECK EMAIL
            // ======================

            if (
                ! isset(
                    $request->guardian['id']
                )
            ) {

                $email =
                    $request->guardian['email'];

                $emailExists =
                    User::where(
                        'email',
                        $email
                    )->exists();

                if ($emailExists) {

                    return response()->json([

                        'message' => 'Email already registered',

                    ], 422);

                }
            }

            // ======================
            // 1. CREATE CHILD
            // OR FIND IF ID PROVIDED
            // ======================

            if (
                isset(
                    $request->child['id']
                )
            ) {

                $child =
                    Child::findOrFail(
                        $request->child['id']
                    );

            } else {

                $child =
                    Child::create(
                        $request->child
                    );

            }

            // ======================
            // 2. CREATE GUARDIAN
            // OR FIND IF ID PROVIDED
            // ======================

            if (
                isset(
                    $request->guardian['id']
                )
            ) {

                $guardian =
                    Guardian::findOrFail(
                        $request->guardian['id']
                    );

            } else {

                $guardian =
                    Guardian::create([

                        'id_number' => $request->guardian['id_number']
                            ?? null,

                        'name' => $request->guardian['name'],

                        'email' => $request->guardian['email'],

                        'phone' => $request->guardian['phone'],

                        'occupation' => $request->guardian['occupation']
                            ?? null,

                        'social_media' => $request->guardian['social_media']
                            ?? null,

                        'address' => $request->guardian['address'],

                    ]);

                // ======================
                // CREATE USER ACCOUNT
                // ======================

                $userService =
                    new CreateGuardianUserService;

                $user =
                    $userService->execute(
                        $guardian->email,
                        $guardian->name,
                        $guardian->phone
                    );

                $guardian->update([

                    'user_id' => $user->id,

                ]);
            }

            // ======================
            // 3. ATTACH PIVOT
            // ======================

            $roleId =
                $request->guardian[
                    'guardian_role_id'
                ];

            try {

                $child->guardians()->attach(
                    $guardian->id,
                    [
                        'guardian_role_id' => $roleId,
                    ]
                );

            } catch (QueryException $e) {

                $child
                    ->guardians()
                    ->updateExistingPivot(
                        $guardian->id,
                        [
                            'guardian_role_id' => $roleId,
                        ]
                    );
            }

            // ======================
            // 4. GENERATE REG NUMBER
            // ======================

            $today =
                now()->format('Ymd');

            $count =
                Registration::whereDate(
                    'created_at',
                    now()
                )->count() + 1;

            $registrationNumber =

                'REG-'.
                $today.
                '-'.
                str_pad(
                    $count,
                    4,
                    '0',
                    STR_PAD_LEFT
                );

            // ======================
            // 5. CREATE REGISTRATION
            // ======================

            $registration =
                Registration::create([

                    'registration_number' => $registrationNumber,

                    'child_id' => $child->id,

                    'program_id' => $request->registration['program_id']
                        ?? null,

                    'payer_id' => $request->registration['payer_id']
                        ?? null,

                    'clinic_id' => $request->registration['clinic_id']
                        ?? null,

                ]);

            // ======================
            // 6. CREATE
            // REGISTRATION PROGRAMS
            // ======================

            if (
                ! empty(
                    $request->registration['program_ids']
                )
            ) {

                foreach (
                    $request->registration['program_ids'] as $programId
                ) {

                    $program =
                        Program::find($programId);

                    if (! $program) {
                        continue;
                    }

                    RegistrationProgram::create([

                        'registration_id' => $registration->id,

                        'program_id' => $program->id,

                        'price' => $program->price,

                        'learning_period_months' => $request->registration['program_duration_months'] ?? 6,

                    ]);
                }

            } elseif (
                ! empty(
                    $request->registration['program_id']
                )
            ) {

                $program =
                    Program::find(
                        $request->registration['program_id']
                    );

                if ($program) {

                    RegistrationProgram::create([

                        'registration_id' => $registration->id,

                        'program_id' => $program->id,

                        'price' => $program->price,

                        'learning_period_months' => $request->registration['program_duration_months'] ?? 6,

                    ]);
                }
            }

            return response()->json([

                'message' => 'Registration created successfully',

                'data' => $registration,

            ]);
        });
    }

    public function show($id)
    {
        $data = Registration::with([
            'child.guardians',
            'clinic',
            'programs.category',
            'billing.paymentStatus',
            'payer',
        ])->findOrFail($id);

        return new RegistrationResource($data);
    }

    public function update(Request $request, $id)
    {
        $this->forbidNonAdmin();

        return DB::transaction(function () use ($request, $id) {

            $registration = Registration::with('billing')
                ->findOrFail($id);

            // ======================
            // LOCK IF NOT UNPAID
            // ======================

            if ($registration->billing) {

                return response()->json([
                    'message' => 'This registration can no longer be edited.',
                ], 422);

            }

            // ======================
            // VALIDATION
            // ======================

            $validated = $request->validate([
                'clinic_id' => 'required|exists:clinics,id',
                'program_category_id' => 'required|exists:program_categories,id',
                'program_ids' => 'required|array|min:1',
                'program_ids.*' => 'exists:programs,id',

                'payer_id' => 'nullable|exists:payers,id',

                'program_duration_months' => 'required|integer|in:6,12',
            ]);

            // ======================
            // UPDATE REGISTRATION
            // ======================

            $registration->update([
                'clinic_id' => $validated['clinic_id'],
                'program_id' => $validated['program_ids'][0],
                'payer_id' => $validated['payer_id'] ?? null,
            ]);

            // ======================
            // REPLACE PROGRAMS
            // ======================

            $registration
                ->registrationPrograms()
                ->delete();

            foreach ($validated['program_ids'] as $programId) {

                $program = Program::find($programId);

                RegistrationProgram::create([

                    'registration_id' => $registration->id,

                    'program_id' => $program->id,

                    'price' => $program->price,
                    'learning_period_months' => $validated['program_duration_months'],

                ]);
            }

            return response()->json([

                'message' => 'Registration updated successfully',

                'data' => $registration->load([
                    'programs',
                    'payer',
                    'billing.paymentStatus',
                ]),
            ]);
        });
    }

    public function publicChildren()
    {
        $children = Child::with([

            'birthplace',

            'hometown',

            'school',

            'schoolClass',

            'schoolEducation',

        ])->get();

        return response()->json([

            'data' => $children,

        ]);
    }

    public function publicGuardians()
    {
        $guardians =
            Guardian::get();

        return response()->json([

            'data' => $guardians,

        ]);
    }

    public function editMasterData($id)
    {
        $registration = Registration::with([

            'child',

            'billing.paymentStatus',

            'programs.category',

            'payer',

            'clinic',

        ])->findOrFail($id);

        $programs = Program::all();

        $payers = Payer::all();

        $clinics = Clinic::all();

        $programCategories = ProgramCategory::all();

        return response()->json([

            'registration' => [

                ...$registration->toArray(),

                'program_category' => optional(
                    $registration->programs->first()
                )->category,

                'program_ids' => $registration
                    ->programs
                    ->pluck('id')
                    ->values(),

                'program_duration_months' => optional(
                    $registration->programs->first()
                )->pivot?->learning_period_months,

            ],

            'programs' => $programs,

            'payers' => $payers,

            'clinics' => $clinics,

            'program_categories' => $programCategories,

        ]);
    }

    public function generateBilling($id)
    {
        $this->forbidNonAdmin();

        return DB::transaction(function () use ($id) {

            $registration = Registration::with([
                'billing',
                'registrationPrograms.program',
            ])->findOrFail($id);

            // ======================
            // ALREADY HAS BILLING
            // ======================

            if ($registration->billing) {

                return response()->json([
                    'message' => 'Billing already exists.',
                ], 422);
            }

            // ======================
            // MUST HAVE PROGRAMS
            // ======================

            if ($registration->registrationPrograms->isEmpty()) {

                return response()->json([
                    'message' => 'No registration programs found.',
                ], 422);
            }

            // ======================
            // GENERATE INVOICE NUMBER
            // ======================

            $today = now()->format('Ymd');

            $count =
                Billing::whereDate(
                    'created_at',
                    today()
                )->count() + 1;

            $invoiceNumber =
                'INV-'.
                $today.
                '-'.
                str_pad(
                    $count,
                    4,
                    '0',
                    STR_PAD_LEFT
                );

            // ======================
            // CREATE BILLING
            // ======================

            $billing = Billing::create([

                'registration_id' => $registration->id,

                'invoice_number' => $invoiceNumber,

                'payment_status_id' => 1,

                'total_amount' => 0,

            ]);

            // ======================
            // CREATE BILLING ITEMS
            // ======================

            $total = 0;

            foreach (
                $registration->registrationPrograms as $item
            ) {

                $subtotal =
                    $item->price;

                BillingItem::create([

                    'billing_id' => $billing->id,

                    'program_id' => $item->program_id,

                    'description' => $item->program?->name ?? '-',

                    'price' => $item->price,

                    'quantity' => 1,

                    'subtotal' => $subtotal,

                ]);

                $total += $subtotal;
            }

            // ======================
            // UPDATE TOTAL
            // ======================

            $billing->update([

                'total_amount' => $total,

            ]);

            return response()->json([

                'message' => 'Billing generated successfully.',

                'data' => $billing->fresh(),

            ]);
        });
    }
}
