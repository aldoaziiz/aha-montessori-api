<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RegistrationProgramSelectionService
{
    /** @return array<int, array{program: Program, learning_period_months: int, price: float}> */
    public function resolve(Request $request, int $programCategoryId): array
    {
        $category = ProgramCategory::query()->findOrFail($programCategoryId);
        $programSelections = $request->input('registration.program_selections');
        $selectionField = 'registration.program_selections';

        if (is_array($programSelections)) {
            $validated = $request->validate([
                'registration.program_selections' => ['required', 'array', 'min:1'],
                'registration.program_selections.*.program_id' => ['required', 'integer', 'distinct', 'exists:programs,id'],
                'registration.program_selections.*.learning_period_months' => ['required', 'integer', 'min:1', 'max:12'],
            ]);
            $selections = $validated['registration']['program_selections'];
        } elseif ($request->filled('registration.program_ids')) {
            $selectionField = 'registration.program_ids';
            $validated = $request->validate([
                'registration.program_ids' => ['required', 'array', 'min:1'],
                'registration.program_ids.*' => ['required', 'integer', 'distinct', 'exists:programs,id'],
                'registration.program_duration_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            ]);
            $period = (int) ($validated['registration']['program_duration_months'] ?? 1);
            $selections = collect($validated['registration']['program_ids'])
                ->map(fn ($programId) => [
                    'program_id' => (int) $programId,
                    'learning_period_months' => $period,
                ])
                ->all();
        } else {
            $selectionField = 'registration.program_id';
            $validated = $request->validate([
                'registration.program_id' => ['required', 'integer', 'exists:programs,id'],
                'registration.program_duration_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            ]);
            $selections = [[
                'program_id' => (int) $validated['registration']['program_id'],
                'learning_period_months' => (int) ($validated['registration']['program_duration_months'] ?? 1),
            ]];
        }

        $programIds = collect($selections)->pluck('program_id')->map(fn ($id) => (int) $id);
        $programs = Program::query()
            ->with(['track', 'status'])
            ->whereIn('id', $programIds)
            ->get()
            ->keyBy('id');

        if ($programs->count() !== $programIds->count()) {
            throw ValidationException::withMessages([
                'registration.program_selections' => 'One or more selected programs are no longer available.',
            ]);
        }

        $resolved = [];
        $selectedSubjects = [];
        $selectedRegistrationFee = false;
        $registrationData = (array) $request->input('registration', []);
        $clinicId = data_get($registrationData, 'clinic_id') ?? $request->input('clinic_id');
        $payerId = data_get($registrationData, 'payer_id') ?? $request->input('payer_id');
        foreach ($selections as $index => $selection) {
            $program = $programs->get((int) $selection['program_id']);
            $errorField = $selectionField === 'registration.program_selections'
                ? "registration.program_selections.$index.program_id"
                : ($selectionField === 'registration.program_ids'
                    ? "registration.program_ids.$index"
                    : 'registration.program_id');

            if ((int) $program->program_category_id !== $programCategoryId) {
                throw ValidationException::withMessages([
                    $errorField => 'Every selected program must belong to the selected program category.',
                ]);
            }

            if ($category->supports_tracks) {
                if (strtolower((string) $program->status?->name) !== 'active') {
                    throw ValidationException::withMessages([
                        $errorField => 'The selected program package is inactive.',
                    ]);
                }
                if ($program->is_registration_fee) {
                    if ($selectedRegistrationFee) {
                        throw ValidationException::withMessages([
                            $errorField => 'Choose at most one annual registration fee.',
                        ]);
                    }
                    if ($program->program_track_id || (int) $program->session_count !== 0) {
                        throw ValidationException::withMessages([
                            $errorField => 'An annual registration fee cannot have a subject track or sessions.',
                        ]);
                    }
                    if ($clinicId && (int) $program->clinic_id !== (int) $clinicId) {
                        throw ValidationException::withMessages([
                            $errorField => 'The annual registration fee does not match the selected school.',
                        ]);
                    }
                    if ($payerId && (int) $program->payer_id !== (int) $payerId) {
                        throw ValidationException::withMessages([
                            $errorField => 'The annual registration fee does not match the selected payer.',
                        ]);
                    }
                    $selectedRegistrationFee = true;
                } elseif ((int) $program->session_count > 0 && ! $program->program_track_id) {
                    throw ValidationException::withMessages([
                        $errorField => 'A track is required for this session-based program.',
                    ]);
                }
                if (! $program->is_registration_fee && $program->track && (int) $program->track->program_category_id !== $programCategoryId) {
                    throw ValidationException::withMessages([
                        $errorField => 'The program track does not belong to the selected category.',
                    ]);
                }
                if (! $program->is_registration_fee && $program->track && ! $program->track->is_active) {
                    throw ValidationException::withMessages([
                        $errorField => 'The selected subject and mode are inactive.',
                    ]);
                }

                if (! $program->is_registration_fee && $program->track) {
                    $subjectKey = mb_strtolower(trim($program->track->subject_name));
                    if (isset($selectedSubjects[$subjectKey])) {
                        throw ValidationException::withMessages([
                            $errorField => 'Choose only one delivery mode and package per subject.',
                        ]);
                    }
                    $selectedSubjects[$subjectKey] = true;
                }
            } elseif ($program->program_track_id) {
                throw ValidationException::withMessages([
                    $errorField => 'Track-based programs require a track-based category.',
                ]);
            }

            $resolved[] = [
                'program' => $program,
                'learning_period_months' => $program->is_registration_fee
                    ? 1
                    : (int) $selection['learning_period_months'],
                'price' => $program->track
                    ? (float) $program->track->price_per_session
                    : (float) $program->price,
            ];
        }

        if ($category->supports_tracks) {
            if ($selectedSubjects === []) {
                throw ValidationException::withMessages([
                    'registration.program_selections' => 'Choose at least one School Readiness subject package.',
                ]);
            }
        }

        return $resolved;
    }
}
