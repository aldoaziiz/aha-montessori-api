<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationResource;
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
use App\Services\Billing\BillingGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    public function index(Request $request)
    {
        $query = Registration::with([
            'child.guardians',
            'programs.category',
            'programs.track',
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

            case 'registration_status':

                $query->orderBy(
                    'registrations.registration_status',
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

            $rules = [
                'child.allergy_history' => ['nullable', 'string'],
                'child.special_condition' => ['nullable', 'string'],
                'child.under_therapy' => ['nullable', 'string'],
            ];

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

            $registrationProgramSelections = [];
            if (
                ! empty($request->input('registration.program_selections'))
                || ! empty($request->input('registration.program_ids'))
                || ! empty($request->input('registration.program_id'))
            ) {
                $registrationProgramSelections = app(\App\Services\RegistrationProgramSelectionService::class)
                    ->resolve($request, (int) $request->input('registration.program_category_id'));
            }

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
                        collect($request->child)->except([
                            'session_started_at',
                            'session_expired_at',
                        ])->all()
                    );

            }

            $isFirstRegistration = ! $child->registrations()->exists();

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
                        name: $guardian->name,
                        email: $guardian->email,
                        phone: $guardian->phone
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

                    'program_category_id' => $request->registration['program_category_id']
                        ?? null,

                    'program_id' => $registrationProgramSelections[0]['program']->id
                        ?? $request->registration['program_id']
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

            foreach ($registrationProgramSelections as $selection) {
                RegistrationProgram::create([
                    'registration_id' => $registration->id,
                    'program_id' => $selection['program']->id,
                    'price' => $selection['price'],
                    'learning_period_months' => $selection['learning_period_months'],
                ]);
            }

            if (
                $isFirstRegistration
                && $registration->registrationPrograms()
                    ->where('price', 1000000)
                    ->whereHas('program', fn ($query) => $query->whereRaw('LOWER(TRIM(name)) = ?', ['registrasi']))
                    ->exists()
            ) {
                $child->initializeSessionValidity(now());
            }

            $registration->syncSessionEntitlement();

            // ======================
            // 7. UPDATE CHILD
            // PROGRAM CATEGORY
            // ======================

            $firstProgram = $registration
                ->registrationPrograms()
                ->with('program')
                ->first();

            if ($firstProgram?->program) {

                $child->update([

                    'program_category_id' => $firstProgram
                        ->program
                        ->program_category_id,

                ]);

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
            'programs.track',
            'registrationPrograms.program.track',
            'registrationPrograms.therapySessions',
            'billing.paymentStatus',
            'payer',
            'therapySessions:id,registration_id,therapy_session_status_id,uses_session',
        ])->findOrFail($id);

        return new RegistrationResource($data);
    }

    public function updateStatus(Request $request, Registration $registration)
    {
        $this->forbidNonAdmin();

        $validated = $request->validate([
            'registration_status' => [
                'required',
                Rule::in(Registration::STATUSES),
            ],
        ]);

        $registration->update([
            'registration_status' => $validated['registration_status'],
        ]);

        return response()->json([
            'message' => 'Registration status updated successfully.',
            'data' => [
                'id' => $registration->id,
                'registration_status' => $registration->registration_status,
                'registration_status_label' => $registration->registration_status_label,
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->forbidNonAdmin();

        return DB::transaction(function () use ($request, $id) {

            $registration = Registration::with('billing')
                ->lockForUpdate()
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
                'program_ids' => 'required_without:program_selections|array|min:1',
                'program_ids.*' => 'required|integer|distinct|exists:programs,id',
                'program_selections' => 'required_without:program_ids|array|min:1',
                'program_selections.*.program_id' => 'required|integer|distinct|exists:programs,id',
                'program_selections.*.learning_period_months' => 'required|integer|min:1|max:12',

                'payer_id' => 'nullable|exists:payers,id',

                'program_duration_months' => 'nullable|integer|min:1|max:12',
            ]);

            $request->merge(['registration' => array_merge(
                (array) $request->input('registration', []),
                array_intersect_key($validated, array_flip([
                    'program_ids',
                    'program_selections',
                    'program_duration_months',
                ]))
            )]);
            $programSelections = app(\App\Services\RegistrationProgramSelectionService::class)
                ->resolve($request, (int) $validated['program_category_id']);
            $targetProgramCategory = ProgramCategory::query()->findOrFail($validated['program_category_id']);
            $hasSessions = $registration->therapySessions()->exists();

            if (
                (int) $registration->program_category_id !== (int) $validated['program_category_id']
                && $hasSessions
            ) {
                return response()->json([
                    'message' => 'The program category cannot be changed while therapy sessions are attached to this registration.',
                ], 422);
            }

            $preserveRegistrationProgramLinks = false;
            if ($targetProgramCategory->supports_tracks && $hasSessions) {
                $existingSelections = $registration->registrationPrograms()
                    ->get(['program_id', 'price', 'learning_period_months'])
                    ->map(fn (RegistrationProgram $item) => [
                        'program_id' => (int) $item->program_id,
                        'price' => round((float) $item->price, 2),
                        'learning_period_months' => (int) $item->learning_period_months,
                    ])
                    ->sortBy('program_id')
                    ->values()
                    ->all();
                $nextSelections = collect($programSelections)
                    ->map(fn (array $selection) => [
                        'program_id' => (int) $selection['program']->id,
                        'price' => round((float) $selection['price'], 2),
                        'learning_period_months' => (int) $selection['learning_period_months'],
                    ])
                    ->sortBy('program_id')
                    ->values()
                    ->all();

                if ($existingSelections !== $nextSelections) {
                    return response()->json([
                        'message' => 'Subject packages and learning periods cannot be changed after sessions have been created.',
                    ], 422);
                }

                $preserveRegistrationProgramLinks = true;
            }

            // ======================
            // UPDATE REGISTRATION
            // ======================

            $registration->update([
                'clinic_id' => $validated['clinic_id'],
                'program_category_id' => $validated['program_category_id'],
                'program_id' => $programSelections[0]['program']->id,
                'payer_id' => $validated['payer_id'] ?? null,
            ]);

            // ======================
            // REPLACE PROGRAMS
            // ======================

            if (! $preserveRegistrationProgramLinks) {
                $registration
                    ->registrationPrograms()
                    ->delete();

                foreach ($programSelections as $selection) {
                    RegistrationProgram::create([
                        'registration_id' => $registration->id,
                        'program_id' => $selection['program']->id,
                        'price' => $selection['price'],
                        'learning_period_months' => $selection['learning_period_months'],
                    ]);
                }
            }

            $registration->syncSessionEntitlement();

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
            'programs.track',
            'registrationPrograms.program.track',

            'programCategory',

            'payer',

            'clinic',

        ])->findOrFail($id);

        $programs = Program::with(['category', 'track', 'status:id,name'])->get();

        $payers = Payer::all();

        $clinics = Clinic::all();

        $programCategories = ProgramCategory::all();

        return response()->json([

            'registration' => [

                ...$registration->toArray(),

                'program_category' => $registration->programCategory,

                'program_ids' => $registration
                    ->programs
                    ->pluck('id')
                    ->values(),

                'program_duration_months' => optional(
                    $registration->programs->first()
                )->pivot?->learning_period_months,

                'program_selections' => $registration->registrationPrograms
                    ->map(fn ($item) => [
                        'program_id' => $item->program_id,
                        'learning_period_months' => $item->learning_period_months,
                    ])
                    ->values(),

            ],

            'programs' => $programs,

            'payers' => $payers,

            'clinics' => $clinics,

            'program_categories' => $programCategories,

        ]);
    }

    public function generateBilling($id, BillingGenerationService $billingGenerationService)
    {
        $this->forbidNonAdmin();

        $validated = request()->validate([
            'discounts' => ['sometimes', 'array'],
            'discounts.*.registration_program_id' => ['required', 'integer', 'distinct'],
            'discounts.*.discount_percentage' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'regex:/^\d{1,3}(\.\d{1,2})?$/',
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
}
