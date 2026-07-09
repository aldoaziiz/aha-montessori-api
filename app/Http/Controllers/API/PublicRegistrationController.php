<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\Guardian;
use App\Models\Program;
use App\Models\Registration;
use App\Models\RegistrationProgram;
use App\Models\User;
use App\Services\Auth\CreateGuardianUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicRegistrationController extends Controller
{
    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {

            // ======================
            // VALIDATION
            // ======================

            $request->validate([

                'child.name' => 'required|string|max:255',
                'child.id_number' => 'required|string|max:255',
                'child.birth_date' => 'required|date',

                'guardian.name' => 'required|string|max:255',
                'guardian.email' => 'required|email',
                'guardian.phone' => 'required|string|max:255',
                'guardian.guardian_role_id' => 'required|integer',

                'registration.program_ids' => 'required|array|min:1',
                'registration.payer_id' => 'required',

            ]);

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
                    $guardian->email,
                    $guardian->name,
                    $guardian->phone
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

            return response()->json([

                'message' => 'Registration created successfully',

                'data' => $registration,

            ], 201);
        });
    }
}
