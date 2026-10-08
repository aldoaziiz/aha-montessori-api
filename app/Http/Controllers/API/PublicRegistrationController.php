<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Child;
use App\Models\Guardian;
use App\Models\Program;
use App\Models\Registration;
use App\Models\RegistrationProgram;
use App\Models\User;
use App\Services\Auth\CreateGuardianUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicRegistrationController extends Controller
{
    public function store(Request $request)
    {
        if (! AppSetting::enabled('public_registration_enabled')) {
            return response()->json([
                'message' => 'Public registration is currently closed.',
            ], 403);
        }

        return DB::transaction(function () use ($request) {

            // ======================
            // VALIDATION
            // ======================

            $request->validate([

                'child.name' => 'required|string|max:255',
                'child.id_number' => 'required|string|max:255',
                'child.birth_date' => 'required|date',
                'child.allergy_history' => 'nullable|string',
                'child.special_condition' => 'nullable|string',
                'child.under_therapy' => 'nullable|string',

                'guardian.name' => 'required|string|max:255',
                'guardian.email' => 'required|email',
                'guardian.phone' => 'required|string|max:255',
                'guardian.guardian_role_id' => 'required|integer',

                'registration.program_ids' => 'required|array|min:1',
                'registration.program_ids.*' => 'required|integer|distinct|exists:programs,id',
                'registration.program_category_id' => 'required|integer|exists:program_categories,id',
                'registration.program_duration_months' => 'required|integer|min:1|max:12',
                'registration.payer_id' => 'required',

            ]);

            $programIds = collect($request->registration['program_ids'])
                ->map(fn ($programId) => (int) $programId)
                ->values();
            $programsById = Program::query()
                ->whereIn('id', $programIds)
                ->get()
                ->keyBy('id');
            $programCategoryIds = $programsById
                ->pluck('program_category_id')
                ->filter()
                ->map(fn ($categoryId) => (int) $categoryId)
                ->unique()
                ->values();
            $selectedProgramCategoryId = (int) $request->registration['program_category_id'];

            if (
                $programsById->count() !== $programIds->count()
                || $programCategoryIds->count() !== 1
                || $programCategoryIds->first() !== $selectedProgramCategoryId
            ) {
                throw ValidationException::withMessages([
                    'registration.program_ids' => 'Every selected program must belong to the selected program category.',
                ]);
            }

            // ======================
            // CHECK EMAIL
            // ======================

            $email = $request->guardian['email'];

            $emailExists = User::where(
                'email',
                $email
            )->exists();

            if ($emailExists) {

                return response()->json([

                    'message' => 'Email already registered',

                ], 422);

            }

            // ======================
            // CREATE CHILD
            // ======================

            $child = Child::create(
                $request->child
            );

            // ======================
            // CREATE GUARDIAN
            // ======================

            $guardian = Guardian::create([

                'id_number' => $request->guardian['id_number']
                    ?? null,

                'name' => $request->guardian['name'],

                'email' => $request->guardian['email'],

                'phone' => $request->guardian['phone'],

                'address' => $request->guardian['address']
                    ?? null,

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

            // ======================
            // ATTACH GUARDIAN
            // ======================

            $child->guardians()->attach(
                $guardian->id,
                [
                    'guardian_role_id' => $request->guardian['guardian_role_id'],
                ]
            );

            // ======================
            // GENERATE REG NUMBER
            // ======================

            $today = now()->format('Ymd');

            $count =
                Registration::whereDate(
                    'created_at',
                    today()
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
            // CREATE REGISTRATION
            // ======================

            $registration =
                Registration::create([

                    'registration_number' => $registrationNumber,

                    'child_id' => $child->id,

                    'complaint' => $request->registration['complaint']
                        ?? null,

                    'program_id' => $request->registration['program_ids'][0]
                        ?? $request->registration['program_id']
                        ?? null,

                    'program_category_id' => $selectedProgramCategoryId,

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

                    $program = $programsById->get((int) $programId);

                    RegistrationProgram::create([
                        'registration_id' => $registration->id,
                        'program_id' => $program->id,
                        'price' => $program->price,
                        'learning_period_months' => $request->registration['program_duration_months'],
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

                    ]);
                }
            }

            $registration->syncSessionEntitlement();
            $child->update([
                'program_category_id' => $selectedProgramCategoryId,
            ]);

            return response()->json([

                'message' => 'Registration created successfully',

                'data' => $registration,

            ], 201);
        });
    }
}
