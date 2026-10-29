<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\ProgramCategorySessionTime;
use App\Models\Registration;
use App\Models\RegistrationProgram;
use App\Models\Staff;
use App\Models\TherapySession;
use App\Services\TherapySessionSlotAvailability;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TherapySessionController extends Controller
{
    public function __construct(
        private readonly TherapySessionSlotAvailability $slotAvailability
    ) {}

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
        $user = auth()->user();
        if (! in_array($user->role, ['admin', 'teacher'], true)) {

            abort(
                403,
                'Forbidden'
            );

        }

        $query = TherapySession::with([
            'therapist.staffRole',
            'therapySessionStatus',
            'registration.child',
            'registration.programs.category',
            'registration.programCategory',
            'registrationProgram.program.track',
            'programCategorySessionTime.trackClass.track',
        ]);

        $request->validate([
            'program_category_id' => 'nullable|integer|exists:program_categories,id',
        ]);

        if ($request->filled('program_category_id')) {
            $categoryId = (int) $request->program_category_id;
            $query->whereHas('registration', function ($registration) use ($categoryId) {
                $registration->where('program_category_id', $categoryId)
                    ->orWhere(function ($legacy) use ($categoryId) {
                        $legacy->whereNull('program_category_id')
                            ->whereHas('programs', fn ($program) => $program->where('program_category_id', $categoryId))
                            ->whereDoesntHave('programs', fn ($program) => $program->where('program_category_id', '!=', $categoryId));
                    });
            });
        }

        // ======================
        // TEACHER TODAY ONLY
        // ======================

        if (
            $user->role ===
            'teacher' &&
            $request->without_activity
        ) {

            $query->where(function ($q) {

                $q->whereDate(
                    'therapy_date',
                    now()->toDateString()
                )
                    ->orWhere(
                        'allow_late_activity',
                        true
                    );

            });

        }

        // ======================
        // SEARCH CHILD
        // ======================

        if ($request->search) {

            $query->whereHas(
                'registration.child',
                function ($q) use ($request) {

                    $q->where(
                        'name',
                        'like',
                        '%'.$request->search.'%'
                    );
                }
            );
        }

        // ======================
        // FILTER DATE
        // ======================

        if ($request->therapy_date) {

            $query->whereDate(
                'therapy_date',
                $request->therapy_date
            );
        }

        // ======================
        // FILTER THERAPIST
        // ======================

        if ($request->therapist_id) {

            $query->where(
                'therapist_id',
                $request->therapist_id
            );
        }

        // ======================
        // FILTER REGISTRATION
        // ======================

        if ($request->registration_id) {

            $query->where(
                'registration_id',
                $request->registration_id
            );
        }

        // filter therapy session status
        if ($request->filled('therapy_session_status_id')) {
            $query->where(
                'therapy_session_status_id',
                $request->therapy_session_status_id
            );
        }

        // ======================
        // SORTING
        // ======================

        $query->orderBy('therapy_date')
            ->orderBy('start_time');

        // ======================
        // PAGINATION
        // ======================

        if ($request->registration_id) {

            return response()->json([
                'data' => $this->withSessionLabels($query->get()),
            ]);
        }

        $data = $query->paginate(
            $request->per_page ?? 10
        );
        $data->setCollection($this->withSessionLabels($data->getCollection()));

        return response()->json($data);
    }

    private function withSessionLabels(Collection $sessions): Collection
    {
        foreach ($sessions as $session) {
            $registration = $session->registration;
            $category = $registration?->programCategory;

            // Legacy registrations may only have a category through their programs.
            if ($registration && ! $registration->program_category_id) {
                $categories = $registration->programs->pluck('category')->filter()->unique('id');
                $category = $categories->count() === 1 ? $categories->first() : null;
            }

            $session->setAttribute('program_category', $category ? [
                'id' => $category->id,
                'name' => $category->name,
            ] : null);
        }

        $slotKey = fn ($categoryId, $start, $end) => implode('|', [
            $categoryId, substr((string) $start, 0, 5), substr((string) $end, 0, 5),
        ]);
        // Include inactive slots so existing schedules keep their session names.
        $sessionTimes = ProgramCategorySessionTime::whereIn(
            'program_category_id',
            $sessions->pluck('program_category.id')->filter()->unique()
        )->orderBy('id')->get()->groupBy(
            fn ($slot) => $slotKey($slot->program_category_id, $slot->start_time, $slot->end_time)
        );

        foreach ($sessions as $session) {
            $linkedSlot = $session->programCategorySessionTime;
            $track = $linkedSlot?->trackClass?->track;
            if ($linkedSlot) {
                $session->setAttribute('session_name', $linkedSlot->session_name);
                $session->setAttribute('track_subject_name', $track?->subject_name);
                $session->setAttribute('track_delivery_mode', $track?->delivery_mode);
                $session->setAttribute('track_class_code', $linkedSlot->trackClass?->class_code);
                continue;
            }

            $matches = $sessionTimes->get($slotKey(
                $session->program_category['id'] ?? null,
                $session->start_time,
                $session->end_time
            ), collect());
            $names = $matches->pluck('session_name')->unique();
            $session->setAttribute('session_name', $names->count() === 1 ? $names->first() : null);
        }

        return $sessions;
    }

    public function store(Request $request)
    {
        $this->forbidNonAdmin();

        $validated = $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'session_time_id' => 'nullable|exists:program_category_session_times,id',
            'registration_program_id' => 'nullable|exists:registration_programs,id',
            'therapist_id' => 'nullable|exists:staff,id',
            'therapy_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
            'notes' => 'nullable',
        ]);

        return DB::transaction(function () use ($validated) {
            $registration = Registration::with([
                'programs',
                'programCategory',
                'registrationPrograms.program.track',
            ])->lockForUpdate()->findOrFail(
                $validated['registration_id']
            );

            $this->validateSessionDates($registration, ['therapy_date' => $validated['therapy_date']]);

            $sessionTime = $this->resolveManualSessionTime(
                $registration,
                $validated,
                true
            );
            $this->slotAvailability->assertSlotUsableForRegistration($registration, $sessionTime, 'session_time_id');
            $registrationProgram = $this->resolveRegistrationProgram(
                $registration,
                $sessionTime,
                isset($validated['registration_program_id']) ? (int) $validated['registration_program_id'] : null,
                'registration_program_id'
            );

            $row = [
                'row' => 1,
                'therapy_date' => Carbon::parse($validated['therapy_date'])->format('Y-m-d'),
                'session_time' => $sessionTime,
                'registration_program' => $registrationProgram,
                'notes' => $validated['notes'] ?? null,
            ];

            $this->lockRelatedSessionTimeSlots([$row]);

            $conflicts = $this->getSessionCreateConflicts(
                (int) $registration->id,
                [$row],
                true
            );

            if (! empty($conflicts)) {
                return response()->json([
                    'message' => $conflicts[0]['message'],
                    'conflicts' => $conflicts,
                ], 422);
            }

            $entitlementError = $this->getScheduledSessionEntitlementError(
                $registration,
                1,
                true,
                [$row]
            );

            if ($entitlementError !== null) {
                return response()->json([
                    'message' => $entitlementError,
                ], 422);
            }

            // buat sesi terapi
            $session = TherapySession::create([
                'registration_id' => $registration->id,
                'registration_program_id' => $registrationProgram?->id,
                'program_category_session_time_id' => $sessionTime->id,
                'therapy_session_status_id' => TherapySession::STATUS_SCHEDULED,
                'uses_session' => false,
                'therapist_id' => null,
                'therapy_date' => $row['therapy_date'],
                'start_time' => $sessionTime->start_time,
                'end_time' => $sessionTime->end_time,

                'notes' => $row['notes'],
            ]);

            return response()->json([
                'message' => 'Therapy session created',
                'data' => $session,
            ]);
        });
    }

    public function bulkValidate(Request $request)
    {
        $this->forbidNonAdmin();

        $validated = $this->validateBulkSessionRequest($request);

        $registration = Registration::with([
            'programs',
            'programCategory',
            'registrationPrograms.program.track',
        ])->findOrFail($validated['registration_id']);
        $this->validateSessionDates(
            $registration,
            collect($validated['sessions'])->mapWithKeys(fn ($row, $index) => [
                "sessions.$index.therapy_date" => $row['therapy_date'],
            ])->all()
        );

        $rows = $this->prepareBulkSessionRows($validated, $registration);

        $conflicts = $this->getSessionCreateConflicts(
            (int) $validated['registration_id'],
            $rows
        );

        $entitlementError = empty($conflicts)
            ? $this->getScheduledSessionEntitlementError($registration, count($rows), false, $rows)
            : null;

        return response()->json([
            'valid' => empty($conflicts) && $entitlementError === null,
            'message' => $entitlementError
                ?? (empty($conflicts)
                ? 'All sessions are available.'
                : 'Some sessions need attention.'),
            'conflicts' => $conflicts,
        ], empty($conflicts) && $entitlementError === null ? 200 : 422);
    }

    public function bulkStore(Request $request)
    {
        $this->forbidNonAdmin();

        $validated = $this->validateBulkSessionRequest($request);

        $result = DB::transaction(function () use ($validated) {
            $registration = Registration::with([
                'programs',
                'programCategory',
                'registrationPrograms.program.track',
            ])->lockForUpdate()->findOrFail($validated['registration_id']);
            $this->validateSessionDates(
                $registration,
                collect($validated['sessions'])->mapWithKeys(fn ($row, $index) => [
                    "sessions.$index.therapy_date" => $row['therapy_date'],
                ])->all()
            );

            $rows = $this->prepareBulkSessionRows($validated, $registration, true);

            $this->lockRelatedSessionTimeSlots($rows);

            $conflicts = $this->getSessionCreateConflicts(
                (int) $validated['registration_id'],
                $rows,
                true
            );

            if (! empty($conflicts)) {
                return [
                    'conflicts' => $conflicts,
                    'entitlement_error' => null,
                    'sessions' => [],
                ];
            }

            $entitlementError = $this->getScheduledSessionEntitlementError(
                $registration,
                count($rows),
                true,
                $rows
            );

            if ($entitlementError !== null) {
                return [
                    'conflicts' => [],
                    'entitlement_error' => $entitlementError,
                    'sessions' => [],
                ];
            }

            $sessions = [];

            foreach ($rows as $row) {
                $sessions[] = TherapySession::create([
                    'registration_id' => $validated['registration_id'],
                    'registration_program_id' => $row['registration_program']?->id,
                    'program_category_session_time_id' => $row['session_time']->id,
                    'therapist_id' => null,
                    'therapy_session_status_id' => TherapySession::STATUS_SCHEDULED,
                    'uses_session' => false,
                    'therapy_date' => $row['therapy_date'],
                    'start_time' => $row['session_time']->start_time,
                    'end_time' => $row['session_time']->end_time,
                    'notes' => $row['notes'],
                ]);
            }

            return [
                'conflicts' => [],
                'entitlement_error' => null,
                'sessions' => $sessions,
            ];
        });

        if (! empty($result['conflicts'])) {
            return response()->json([
                'message' => 'Some sessions are no longer available.',
                'conflicts' => $result['conflicts'],
            ], 422);
        }

        if ($result['entitlement_error'] !== null) {
            return response()->json([
                'message' => $result['entitlement_error'],
            ], 422);
        }

        return response()->json([
            'message' => count($result['sessions']).' sessions created successfully.',
            'data' => $result['sessions'],
        ]);
    }

    private function validateSessionDates(Registration $registration, array $dates): void
    {
        $errors = [];
        foreach ($dates as $field => $date) {
            $message = null;
            if (! $registration->canScheduleSessions()) {
                $message = 'This registration is '.$registration->registration_status_label
                    .'. New scheduling is not allowed.';
            }

            if ($message !== null) {
                if (preg_match('/^sessions\\.(\\d+)\\./', $field, $matches)) {
                    $message = 'Row '.((int) $matches[1] + 1).': '.$message;
                }
                $errors[$field] = $message;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function getScheduledSessionEntitlementError(
        Registration $registration,
        int $additionalSessions,
        bool $lockForUpdate = false,
        array $rows = []
    ): ?string {
        if ($registration->total_session === null) {
            return 'Session totals have not been set for this registration.';
        }

        if ($registration->programCategory?->supports_tracks) {
            $countsByProgram = collect($rows)
                ->groupBy(fn (array $row) => $row['registration_program']?->id);

            if ($countsByProgram->has('')) {
                return 'Each subject session must be linked to its registration package.';
            }

            foreach ($countsByProgram as $registrationProgramId => $programRows) {
                if (! $registrationProgramId) {
                    return 'Each subject session must be linked to its registration package.';
                }
                $registrationProgram = $programRows->first()['registration_program'];
                $total = (int) ($registrationProgram->program?->session_count ?? 0)
                    * (int) $registrationProgram->learning_period_months;
                $query = TherapySession::query()
                    ->where('registration_program_id', $registrationProgram->id)
                    ->where(function ($query) {
                        $query->where('uses_session', true)
                            ->orWhere('therapy_session_status_id', TherapySession::STATUS_SCHEDULED);
                    });
                $created = $lockForUpdate
                    ? $query->lockForUpdate()->get(['id'])->count()
                    : $query->count();

                if ($created + $programRows->count() > $total) {
                    $remaining = max($total - $created, 0);
                    return 'Only '.$remaining.' sessions remain for '
                        .($registrationProgram->program?->track?->subject_name ?? 'this subject').'.';
                }
            }

            return null;
        }

        $scheduledQuery = TherapySession::where(
            'registration_id',
            $registration->id
        )->where(
            'therapy_session_status_id',
            TherapySession::STATUS_SCHEDULED
        );

        $scheduledSessions = $lockForUpdate
            ? $scheduledQuery->lockForUpdate()->get(['id'])->count()
            : $scheduledQuery->count();

        if ($scheduledSessions + $additionalSessions <= (int) $registration->total_session) {
            return null;
        }

        return 'Scheduling these sessions would exceed the registration session total of '
            .(int) $registration->total_session.'.';
    }

    private function validateBulkSessionRequest(Request $request): array
    {
        return $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'sessions' => 'required|array|min:1',
            'sessions.*.therapy_date' => 'required|date',
            'sessions.*.session_time_id' => 'required|exists:program_category_session_times,id',
            'sessions.*.registration_program_id' => 'nullable|exists:registration_programs,id',
            'sessions.*.notes' => 'nullable|string',
        ]);
    }

    private function prepareBulkSessionRows(
        array $validated,
        Registration $registration,
        bool $lockSessionTimes = false
    ): array
    {
        $sessionTimeIds = collect($validated['sessions'])
            ->pluck('session_time_id')
            ->unique()
            ->values()
            ->all();

        $sessionTimesQuery = ProgramCategorySessionTime::whereIn('id', $sessionTimeIds);
        $sessionTimesQuery->with('trackClass.track');

        if ($lockSessionTimes) {
            $sessionTimesQuery->lockForUpdate();
        }

        $sessionTimes = $sessionTimesQuery->get()->keyBy('id');

        return collect($validated['sessions'])
            ->map(function ($row, $index) use ($sessionTimes, $registration) {
                $sessionTime = $sessionTimes->get($row['session_time_id']);

                if (! $sessionTime) {
                    abort(422, 'Selected session time is invalid.');
                }

                $this->slotAvailability->assertSlotUsableForRegistration(
                    $registration,
                    $sessionTime,
                    "sessions.$index.session_time_id"
                );
                $registrationProgram = $this->resolveRegistrationProgram(
                    $registration,
                    $sessionTime,
                    isset($row['registration_program_id']) ? (int) $row['registration_program_id'] : null,
                    "sessions.$index.registration_program_id"
                );

                return [
                    'row' => $index + 1,
                    'therapy_date' => Carbon::parse($row['therapy_date'])->format('Y-m-d'),
                    'session_time' => $sessionTime,
                    'registration_program' => $registrationProgram,
                    'notes' => $row['notes'] ?? null,
                ];
            })
            ->all();
    }

    private function resolveManualSessionTime(
        Registration $registration,
        array $validated,
        bool $lockSessionTime = false
    ): ProgramCategorySessionTime {
        $sessionTimeQuery = ProgramCategorySessionTime::query()->with('trackClass.track');

        if ($lockSessionTime) {
            $sessionTimeQuery->lockForUpdate();
        }

        if (! empty($validated['session_time_id'])) {
            return $sessionTimeQuery->findOrFail($validated['session_time_id']);
        }

        $programCategoryId = $this->slotAvailability->categoryIdFor($registration);

        $sessionTime = $sessionTimeQuery
            ->where('program_category_id', $programCategoryId)
            ->whereTime('start_time', $validated['start_time'])
            ->whereTime('end_time', $validated['end_time'])
            ->first();

        if (! $sessionTime) {
            abort(422, 'Selected session time is invalid.');
        }

        return $sessionTime;
    }

    private function resolveRegistrationProgram(
        Registration $registration,
        ProgramCategorySessionTime $sessionTime,
        ?int $requestedId,
        string $field
    ): ?RegistrationProgram {
        $trackBased = (bool) ($registration->programCategory?->supports_tracks);
        $trackId = (int) $sessionTime->trackClass?->track?->id;

        if (! $trackBased) {
            if ($sessionTime->program_track_class_id) {
                throw ValidationException::withMessages([
                    $field => 'A regular registration cannot use a subject track slot.',
                ]);
            }

            return null;
        }

        if ($trackId <= 0 || ! $sessionTime->trackClass?->is_active || ! $sessionTime->trackClass?->track?->is_active) {
            throw ValidationException::withMessages([
                $field => 'The selected subject session is inactive or unavailable.',
            ]);
        }

        $programs = $registration->relationLoaded('registrationPrograms')
            ? $registration->registrationPrograms
            : $registration->registrationPrograms()->with('program.track')->get();
        $matchedProgram = $programs->first(fn (RegistrationProgram $item) =>
            (int) $item->program?->program_track_id === $trackId
        );

        if (! $matchedProgram || ($requestedId !== null && (int) $matchedProgram->id !== $requestedId)) {
            throw ValidationException::withMessages([
                $field => 'The registration does not contain a package for this subject and mode.',
            ]);
        }

        return $matchedProgram;
    }

    private function getSessionCreateConflicts(
        int $registrationId,
        array $rows,
        bool $lockConflicts = false
    ): array {
        $registration = Registration::query()
            ->with(['programCategory', 'child'])
            ->findOrFail($registrationId);
        $trackBased = (bool) $registration->programCategory?->supports_tracks;
        $conflicts = [];
        $slotCounts = [];
        $submittedRegistrationSlots = [];
        $submittedChildIntervals = [];

        foreach ($rows as $row) {
            $sessionTime = $row['session_time'];
            $slotKey = $trackBased
                ? $row['therapy_date'].'|slot:'.$sessionTime->id
                : $this->makeSessionSlotKey(
                    $row['therapy_date'],
                    $sessionTime->start_time,
                    $sessionTime->end_time
                ).'|'.$sessionTime->program_category_id;

            if (isset($submittedRegistrationSlots[$slotKey])) {
                $conflicts[] = $this->makeSessionConflict(
                    $row,
                    'duplicate_submission',
                    'This session is duplicated in this submission.'
                );

                continue;
            }

            $submittedRegistrationSlots[$slotKey] = true;

            $duplicateQuery = TherapySession::where('registration_id', $registrationId)
                ->whereDate('therapy_date', $row['therapy_date']);
            if ($trackBased) {
                $duplicateQuery->where('program_category_session_time_id', $sessionTime->id);
            } else {
                $duplicateQuery->whereTime('start_time', $sessionTime->start_time)
                    ->whereTime('end_time', $sessionTime->end_time);
            }

            $duplicate = $lockConflicts
                ? $duplicateQuery->lockForUpdate()->get(['id'])->isNotEmpty()
                : $duplicateQuery->exists();

            if ($duplicate) {
                $conflicts[] = $this->makeSessionConflict(
                    $row,
                    'duplicate_existing',
                    'This session already exists for this child.'
                );

                continue;
            }

            if ($trackBased) {
                $overlapQuery = TherapySession::query()
                    ->whereDate('therapy_date', $row['therapy_date'])
                    ->whereTime('start_time', '<', $sessionTime->end_time)
                    ->whereTime('end_time', '>', $sessionTime->start_time)
                    ->whereHas('registration', fn ($query) => $query->where('child_id', $registration->child_id));
                $hasExistingOverlap = $lockConflicts
                    ? $overlapQuery->lockForUpdate()->get(['id'])->isNotEmpty()
                    : $overlapQuery->exists();
                $hasSubmittedOverlap = collect($submittedChildIntervals[$row['therapy_date']] ?? [])
                    ->contains(fn (array $interval) =>
                        (string) $sessionTime->start_time < $interval['end']
                        && (string) $sessionTime->end_time > $interval['start']
                    );

                if ($hasExistingOverlap || $hasSubmittedOverlap) {
                    $conflicts[] = $this->makeSessionConflict(
                        $row,
                        'child_time_overlap',
                        'This child already has another session at an overlapping time.'
                    );
                    continue;
                }
                $submittedChildIntervals[$row['therapy_date']][] = [
                    'start' => (string) $sessionTime->start_time,
                    'end' => (string) $sessionTime->end_time,
                ];
            }

            if (! array_key_exists($slotKey, $slotCounts)) {
                $slotCounts[$slotKey] = $this->slotAvailability->countOccupancy(
                    $row['therapy_date'],
                    $sessionTime,
                    $lockConflicts
                );
            }

            if ($slotCounts[$slotKey] >= $sessionTime->capacity) {
                $conflicts[] = $this->makeSessionConflict(
                    $row,
                    'slot_full',
                    'This session slot is full.',
                    $slotCounts[$slotKey],
                    $sessionTime->capacity
                );

                continue;
            }

            $slotCounts[$slotKey]++;
        }

        return $conflicts;
    }

    private function makeSessionSlotKey(string $date, string $startTime, string $endTime): string
    {
        return implode('|', [
            $date,
            Carbon::parse($startTime)->format('H:i:s'),
            Carbon::parse($endTime)->format('H:i:s'),
        ]);
    }

    private function makeSessionConflict(
        array $row,
        string $type,
        string $message,
        ?int $occupied = null,
        ?int $capacity = null
    ): array {
        return [
            'row' => $row['row'],
            'type' => $type,
            'message' => $message,
            'therapy_date' => $row['therapy_date'],
            'session_name' => $row['session_time']->session_name,
            'start_time' => Carbon::parse($row['session_time']->start_time)->format('H:i'),
            'end_time' => Carbon::parse($row['session_time']->end_time)->format('H:i'),
            'occupied' => $occupied,
            'capacity' => $capacity,
        ];
    }

    public function update(Request $request, $id)
    {
        $this->forbidNonAdmin();

        $validated = $request->validate([
            'therapist_id' => 'nullable|exists:staff,id',
            'session_time_id' => 'nullable|exists:program_category_session_times,id',
            'therapy_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $id) {
            $session = TherapySession::query()->lockForUpdate()->findOrFail($id);

            if ($session->activity) {
                return response()->json([
                    'message' => 'Completed sessions cannot be edited.',
                ], 422);
            }

            $registration = Registration::with([
                'programs',
                'programCategory',
                'registrationPrograms.program.track',
            ])->lockForUpdate()->findOrFail(
                $session->registration_id
            );
            $targetDate = Carbon::parse($validated['therapy_date'])->toDateString();
            $selectedSessionTime = null;
            if (! empty($validated['session_time_id'])) {
                $selectedSessionTime = ProgramCategorySessionTime::query()
                    ->with('trackClass.track')
                    ->lockForUpdate()
                    ->findOrFail($validated['session_time_id']);
            }
            $targetStart = Carbon::parse($selectedSessionTime?->start_time ?? $validated['start_time'])->format('H:i:s');
            $targetEnd = Carbon::parse($selectedSessionTime?->end_time ?? $validated['end_time'])->format('H:i:s');
            $scheduleChanged = Carbon::parse($session->therapy_date)->toDateString() !== $targetDate
                || Carbon::parse($session->start_time)->format('H:i:s') !== $targetStart
                || Carbon::parse($session->end_time)->format('H:i:s') !== $targetEnd
                || ($selectedSessionTime && (int) $session->program_category_session_time_id !== (int) $selectedSessionTime->id);

            if ($scheduleChanged) {
                $this->validateSessionDates($registration, ['therapy_date' => $targetDate]);
            }

            $duplicate = TherapySession::query()
                ->where('registration_id', $session->registration_id)
                ->where('id', '!=', $session->id)
                ->whereDate('therapy_date', $targetDate)
                ->whereTime('start_time', $targetStart)
                ->whereTime('end_time', $targetEnd)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'message' => 'This session already exists.',
                ], 422);
            }

            if ($scheduleChanged) {
                if (! $selectedSessionTime && $registration->programCategory?->supports_tracks) {
                    throw ValidationException::withMessages([
                        'session_time_id' => 'Select a specific subject session time.',
                    ]);
                }

                if (! $selectedSessionTime) {
                    $categoryId = $this->slotAvailability->categoryIdFor($registration, 'start_time');
                    $targetSlots = ProgramCategorySessionTime::query()
                        ->where('program_category_id', $categoryId)
                        ->whereNull('program_track_class_id')
                        ->whereTime('start_time', $targetStart)
                        ->whereTime('end_time', $targetEnd)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($targetSlots->count() !== 1) {
                        throw ValidationException::withMessages([
                            'start_time' => 'The selected time is not a valid session for this program category.',
                        ]);
                    }

                    $selectedSessionTime = $targetSlots->first();
                }

                $sessionTime = $selectedSessionTime;
                $this->slotAvailability->assertSlotUsableForRegistration(
                    $registration,
                    $sessionTime,
                    'session_time_id'
                );
                $registrationProgram = $this->resolveRegistrationProgram(
                    $registration,
                    $sessionTime,
                    $session->registration_program_id ? (int) $session->registration_program_id : null,
                    'session_time_id'
                );

                if ($registration->programCategory?->supports_tracks) {
                    $hasChildOverlap = TherapySession::query()
                        ->where('id', '!=', $session->id)
                        ->whereDate('therapy_date', $targetDate)
                        ->whereTime('start_time', '<', $targetEnd)
                        ->whereTime('end_time', '>', $targetStart)
                        ->whereHas('registration', fn ($query) => $query->where('child_id', $registration->child_id))
                        ->exists();

                    if ($hasChildOverlap) {
                        throw ValidationException::withMessages([
                            'session_time_id' => 'This child already has another session at an overlapping time.',
                        ]);
                    }
                }

                $row = ['therapy_date' => $targetDate, 'session_time' => $sessionTime];
                $this->lockRelatedSessionTimeSlots([$row]);
                $occupied = $this->slotAvailability->countOccupancy(
                    $targetDate,
                    $sessionTime,
                    true,
                    (int) $session->id
                );

                if ((int) $sessionTime->capacity <= 0 || $occupied >= (int) $sessionTime->capacity) {
                    return response()->json([
                        'message' => 'This session slot is full.',
                        'conflicts' => [[
                            'type' => 'slot_full',
                            'therapy_date' => $targetDate,
                            'session_name' => $sessionTime->session_name,
                            'start_time' => substr((string) $sessionTime->start_time, 0, 5),
                            'end_time' => substr((string) $sessionTime->end_time, 0, 5),
                            'occupied' => $occupied,
                            'capacity' => (int) $sessionTime->capacity,
                        ]],
                    ], 422);
                }
            }

            $session->update([
                'therapist_id' => null,
                'registration_program_id' => $scheduleChanged
                    ? ($registrationProgram?->id ?? null)
                    : $session->registration_program_id,
                'program_category_session_time_id' => $scheduleChanged
                    ? $selectedSessionTime?->id
                    : $session->program_category_session_time_id,
                'therapy_date' => $targetDate,
                'start_time' => $targetStart,
                'end_time' => $targetEnd,
                'notes' => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'message' => 'Session updated successfully.',
                'data' => $session->fresh([
                    'therapist',
                    'therapySessionStatus',
                ]),
            ]);
        }, 3);
    }

    public function destroy($id)
    {
        $this->forbidNonAdmin();

        $session = TherapySession::findOrFail($id);

        if ($session->activity) {
            return response()->json([
                'message' => 'Completed sessions cannot be deleted.',
            ], 422);
        }
        $session->delete();

        return response()->json([
            'message' => 'Session deleted',
        ]);
    }

    public function generate(Request $request)
    {
        $this->forbidNonAdmin();

        $validated = $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'notes' => 'nullable|string',
            'schedule_configs' => 'required|array|min:1|max:42',
            'schedule_configs.*.day' => 'required|integer|between:0,6',
            'schedule_configs.*.session_time_id' => 'required|exists:program_category_session_times,id',
            'schedule_configs.*.registration_program_id' => 'nullable|exists:registration_programs,id',
        ]);

        return DB::transaction(function () use ($validated) {
            $registration = Registration::with([
                'programs',
                'programCategory',
                'registrationPrograms.program.track',
            ])->lockForUpdate()->findOrFail($validated['registration_id']);
            $trackBased = (bool) $registration->programCategory?->supports_tracks;

            if (! $trackBased && collect($validated['schedule_configs'])->pluck('day')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'schedule_configs' => 'Only one session time may be configured per weekday for this program category.',
                ]);
            }

            $this->validateSessionDates($registration, [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ]);

            if ($registration->total_session === null) {
                return response()->json(['message' => 'Session totals have not been set for this registration.'], 422);
            }

            $existingSessions = $registration->therapySessions()->orderBy('id')->lockForUpdate()->get();
            $registration->setRelation('therapySessions', $existingSessions);
            $scheduled = $existingSessions->where('therapy_session_status_id', TherapySession::STATUS_SCHEDULED)->count();
            $remainingByProgram = [];
            if ($trackBased) {
                $configuredProgramIds = collect($validated['schedule_configs'])
                    ->pluck('registration_program_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();
                if ($configuredProgramIds->isEmpty()) {
                    throw ValidationException::withMessages([
                        'schedule_configs' => 'Choose the subject package for every configured session time.',
                    ]);
                }
                $configuredPrograms = $registration->registrationPrograms
                    ->whereIn('id', $configuredProgramIds)
                    ->values();
                if ($configuredPrograms->count() !== $configuredProgramIds->count()) {
                    throw ValidationException::withMessages([
                        'schedule_configs' => 'One or more selected packages do not belong to this registration.',
                    ]);
                }
                foreach ($configuredPrograms as $item) {
                    $total = (int) ($item->program?->session_count ?? 0) * (int) $item->learning_period_months;
                    $created = TherapySession::query()
                        ->where('registration_program_id', $item->id)
                        ->where(fn ($query) => $query->where('uses_session', true)
                            ->orWhere('therapy_session_status_id', TherapySession::STATUS_SCHEDULED))
                        ->count();
                    $remainingByProgram[(int) $item->id] = max($total - $created, 0);
                }
                $targetSessions = array_sum($remainingByProgram);
            } else {
                $targetSessions = max($registration->getRemainingSessionCount() - $scheduled, 0);
            }

            if ($targetSessions === 0) {
                return response()->json(['message' => 'No additional sessions need to be scheduled.'], 422);
            }

            $generatedSchedules = $this->generateSchedules(
                $validated['start_date'],
                $validated['end_date'],
                $validated['schedule_configs'],
                $targetSessions,
                $existingSessions->all(),
                $trackBased,
                $remainingByProgram
            );

            if (empty($generatedSchedules)) {
                return response()->json(['message' => 'No new sessions match the selected dates and weekly schedule.'], 422);
            }

            foreach ($generatedSchedules as &$schedule) {
                $this->slotAvailability->assertSlotUsableForRegistration(
                    $registration,
                    $schedule['session_time'],
                    'schedule_configs'
                );
                $schedule['registration_program'] = $this->resolveRegistrationProgram(
                    $registration,
                    $schedule['session_time'],
                    isset($schedule['registration_program_id']) ? (int) $schedule['registration_program_id'] : null,
                    'schedule_configs'
                );
            }
            unset($schedule);

            $this->lockSessionTimesForSchedules($generatedSchedules);
            [
                'validSchedules' => $validSchedules,
                'conflicts' => $conflicts,
            ] = $this->checkConflicts($generatedSchedules, (int) $registration->child_id);

            if (! empty($conflicts)) {
                return response()->json([
                    'message' => 'Some selected sessions are already full. No sessions were created.',
                    'conflicts' => $conflicts,
                ], 422);
            }

            $entitlementError = $this->getScheduledSessionEntitlementError(
                $registration,
                count($validSchedules),
                true,
                $validSchedules
            );
            if ($entitlementError !== null) {
                return response()->json(['message' => $entitlementError], 422);
            }

            $sessions = $this->createSessions(
                $registration->id,
                $validated['notes'] ?? null,
                $validSchedules
            );

            return response()->json([
                'message' => count($sessions).' sessions generated successfully.',
                'target_sessions' => $targetSessions,
                'generated_sessions' => count($sessions),
                'data' => $sessions,
            ]);
        }, 3);
    }

    private function generateSchedules(
        string $startDate,
        string $endDate,
        array $scheduleConfigs,
        int $totalSessions,
        array $existingSessions = [],
        bool $trackBased = false,
        array $remainingByProgram = []
    ): array {
        $generatedSchedules = [];
        $currentDate = Carbon::parse($startDate)->startOfDay();
        $lastDate = Carbon::parse($endDate)->startOfDay();

        $sessionTimes = ProgramCategorySessionTime::whereIn(
            'id',
            collect($scheduleConfigs)->pluck('session_time_id')
        )->with('trackClass.track')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $existingKeys = [];
        foreach ($existingSessions as $session) {
            $existingKeys[$trackBased && $session->program_category_session_time_id
                ? Carbon::parse($session->therapy_date)->toDateString().'|slot:'.$session->program_category_session_time_id
                : $this->makeSessionSlotKey(
                    Carbon::parse($session->therapy_date)->toDateString(),
                    $session->start_time,
                    $session->end_time
                )] = true;
        }

        while ($currentDate->lte($lastDate) && count($generatedSchedules) < $totalSessions) {
            foreach ($scheduleConfigs as $config) {
                if ($currentDate->dayOfWeek !== (int) $config['day']) {
                    continue;
                }

                $sessionTime = $sessionTimes->get($config['session_time_id']);
                if (! $sessionTime) {
                    throw ValidationException::withMessages([
                        'schedule_configs' => 'A selected session time is no longer available.',
                    ]);
                }

                $registrationProgramId = (int) ($config['registration_program_id'] ?? 0);
                if ($trackBased && ($remainingByProgram[$registrationProgramId] ?? 0) <= 0) {
                    continue;
                }

                $date = $currentDate->toDateString();
                $key = $trackBased
                    ? $date.'|slot:'.$sessionTime->id
                    : $this->makeSessionSlotKey($date, $sessionTime->start_time, $sessionTime->end_time);
                if (! isset($existingKeys[$key])) {
                    $generatedSchedules[] = [
                        'therapy_date' => $date,
                        'session_time' => $sessionTime,
                        'registration_program_id' => $trackBased ? $registrationProgramId : null,
                    ];
                    $existingKeys[$key] = true;
                    if ($trackBased) {
                        $remainingByProgram[$registrationProgramId]--;
                    }
                }
                if (! $trackBased) {
                    break;
                }
            }
            $currentDate->addDay();
        }

        return $generatedSchedules;
    }

    private function lockSessionTimesForSchedules(array $schedules): void
    {
        $sessionTimeIds = collect($schedules)
            ->map(fn ($schedule) => $schedule['session_time']?->id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($sessionTimeIds)) {
            return;
        }

        ProgramCategorySessionTime::whereIn('id', $sessionTimeIds)
            ->lockForUpdate()
            ->get();

        $this->lockRelatedSessionTimeSlots($schedules);
    }

    private function lockRelatedSessionTimeSlots(array $rows): void
    {
        $this->slotAvailability->lockDefinitions($rows);
    }

    private function checkConflicts(array $generatedSchedules, ?int $childId = null): array
    {
        $validSchedules = [];

        $conflicts = [];

        $slotCounts = [];

        $conflictKeys = [];
        $acceptedIntervalsByDate = [];

        foreach ($generatedSchedules as $schedule) {

            $sessionTime = $schedule['session_time'];
            $trackBased = (bool) $sessionTime->program_track_class_id;

            $capacity = $sessionTime->capacity;

            $key = $trackBased
                ? $schedule['therapy_date'].'|slot:'.$sessionTime->id
                : implode('|', [
                    $sessionTime->program_category_id,
                    $schedule['therapy_date'],
                    $sessionTime->start_time,
                    $sessionTime->end_time,
                ]);

            if ($trackBased && $childId) {
                $existingOverlap = TherapySession::query()
                    ->whereDate('therapy_date', $schedule['therapy_date'])
                    ->whereTime('start_time', '<', $sessionTime->end_time)
                    ->whereTime('end_time', '>', $sessionTime->start_time)
                    ->whereHas('registration', fn ($query) => $query->where('child_id', $childId))
                    ->exists();
                $generatedOverlap = collect($acceptedIntervalsByDate[$schedule['therapy_date']] ?? [])
                    ->contains(fn (array $interval) =>
                        (string) $sessionTime->start_time < $interval['end']
                        && (string) $sessionTime->end_time > $interval['start']
                    );
                if ($existingOverlap || $generatedOverlap) {
                    $conflicts[] = $this->makeSessionConflict(
                        ['row' => count($validSchedules) + 1, 'therapy_date' => $schedule['therapy_date'], 'session_time' => $sessionTime],
                        'child_time_overlap',
                        'This child already has another session at an overlapping time.'
                    );
                    continue;
                }
            }

            // Query database hanya sekali untuk setiap slot
            if (! array_key_exists($key, $slotCounts)) {

                $slotCounts[$key] = $this->slotAvailability->countOccupancy(
                    $schedule['therapy_date'],
                    $sessionTime,
                    true
                );
            }

            // Slot sudah penuh
            if ($slotCounts[$key] >= $capacity) {

                if (! isset($conflictKeys[$key])) {

                    $conflictKeys[$key] = true;

                    $conflicts[] = [

                        'therapy_date' => $schedule['therapy_date'],

                        'day' => Carbon::parse(
                            $schedule['therapy_date']
                        )->format('l'),

                        'session_name' => $sessionTime->session_name,

                        'start_time' => $sessionTime->start_time,

                        'end_time' => $sessionTime->end_time,

                        'capacity' => $capacity,

                        'occupied' => $slotCounts[$key],

                    ];
                }

                continue;
            }

            $validSchedules[] = $schedule;

            $slotCounts[$key]++;
            if ($trackBased) {
                $acceptedIntervalsByDate[$schedule['therapy_date']][] = [
                    'start' => (string) $sessionTime->start_time,
                    'end' => (string) $sessionTime->end_time,
                ];
            }
        }

        return [

            'validSchedules' => $validSchedules,

            'conflicts' => $conflicts,

        ];
    }

    private function createSessions(
        int $registrationId,
        ?string $notes,
        array $validSchedules
    ): array {

        $sessions = [];

        foreach ($validSchedules as $schedule) {

            $sessions[] = TherapySession::create([

                'registration_id' => $registrationId,
                'registration_program_id' => $schedule['registration_program']?->id,
                'program_category_session_time_id' => $schedule['session_time']->id,

                'therapist_id' => null,

                'therapy_session_status_id' => TherapySession::STATUS_SCHEDULED,

                'uses_session' => false,

                'therapy_date' => $schedule['therapy_date'],

                'start_time' => $schedule['session_time']->start_time,

                'end_time' => $schedule['session_time']->end_time,

                'notes' => $notes,

            ]);
        }

        return $sessions;
    }

    public function availability(Request $request)
    {

        $startDate = $request->start_date;

        $endDate = $request->end_date;

        $therapistId = $request->therapist_id;

        $therapists = Staff::query()
            ->whereHas('user', function ($query) {
                $query->where('role', 'teacher');
            });

        if ($therapistId) {

            $therapists->where(
                'id',
                $therapistId
            );
        }

        $therapists = $therapists
            ->orderBy('name')
            ->get();

        $sessions = TherapySession::with([
            'registration.child',
        ])
            ->whereBetween(
                'therapy_date',
                [
                    $startDate,
                    $endDate,
                ]
            )
            ->get();

        return response()->json([
            'therapists' => $therapists,
            'sessions' => $sessions,
        ]);
    }

    public function grid(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $sessions = TherapySession::with([
            'registration.child',
            'registration.programs.category',
            'registration.programCategory',
        ])
            ->whereBetween('therapy_date', [
                $validated['start_date'],
                $validated['end_date'],
            ])
            ->orderBy('therapy_date')
            ->orderBy('start_time')
            ->get();

        return $sessions->map(function ($session) {
            $registration = $session->registration;
            $category = $registration?->programCategory;

            if ($registration && ! $category) {
                $categories = $registration->programs
                    ->pluck('category')
                    ->filter()
                    ->unique('id');
                $category = $categories->count() === 1
                    ? $categories->first()
                    : null;
            }

            return [

                'id' => $session->id,

                'therapy_date' => $session->therapy_date,

                'start_time' => substr($session->start_time, 0, 5),

                'end_time' => substr($session->end_time, 0, 5),

                'child_id' => $registration?->child_id,

                'child_name' => $registration?->child?->name,

                'program_category' => $category?->name,

                'program_category_session_time_id' => $session->program_category_session_time_id,

                'therapy_session_status_id' => $session->therapy_session_status_id,

            ];

        });
    }

    public function gridDemo(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $names = [
            'Muhammad Arkana Pratama',
            'Azzam Fadillah',
            'Alya Putri Ramadhani',
            'Aisyah Nabila',
            'Azka Alfarizi',
            'Benjamin Jonathan',
            'Brigitte Valencia',
            'Calista Aurelia',
            'Darren Wijaya',
            'Dinda Maharani',
            'Elvano Saputra',
            'Farrel Mahendra',
            'Farel Ramadhan',
            'Fiona Clarissa',
            'Gavin Alexander',
            'Hana Putri',
            'Ibra Alghifari',
            'Jihan Azzahra',
            'Kaira Humaira',
            'Kayla Anindita',
            'Keenan Alvaro',
            'Keyla Putri',
            'Luna Amalia',
            'Mika Prakoso',
            'Naura Khairunnisa',
            'Nadine Valencia',
            'Nayla Putri',
            'Olivia Nathania',
            'Qinan Pratama',
            'Rafa Maulana',
            'Rafif Akbar',
            'Rania Putri',
            'Rasya Ramadhan',
            'Riko Saputra',
            'Salma Zahra',
            'Satria Nugraha',
            'Shaka Pratama',
            'Shakira Azzahra',
            'Tasya Maharani',
            'Vano Prasetyo',
            'Viona Clarissa',
            'Yasmin Aurelia',
            'Zayn Alfatih',
            'Zahra Khairunnisa',
            'Zidan Prakoso',
            'Alif Ramadhan',
            'Alvaro Mahendra',
            'Bella Anastasya',
            'Celine Aurelia',
            'Daffa Ramadhan',
            'Damar Saputra',
            'Evan Christian',
            'Faris Alghifari',
            'Gio Mahardika',
            'Hazel Nathania',
            'Intan Permata',
            'Jovanka Aurelia',
            'Kinan Maharani',
            'Liam Jonathan',
            'Mila Putri',
            'Niko Saputra',
            'Putri Maharani',
            'Queen Valencia',
            'Rendra Saputra',
            'Salsa Azzahra',
            'Tegar Prakoso',
            'Umar Faruq',
            'Valen Christian',
            'Wafi Ramadhan',
            'Xavier Jonathan',
            'Yudha Saputra',
            'Zaki Alghifari',
        ];

        $slots = [
            [
                'category' => 'TODDLER',
                'start' => '08:00',
                'end' => '09:30',
                'max' => 10,
            ],
            [
                'category' => 'TODDLER',
                'start' => '10:30',
                'end' => '12:00',
                'max' => 10,
            ],
            [
                'category' => 'TODDLER',
                'start' => '15:00',
                'end' => '16:30',
                'max' => 10,
            ],
            [
                'category' => 'KINDER',
                'start' => '08:00',
                'end' => '10:00',
                'max' => 8,
            ],
            [
                'category' => 'KINDER',
                'start' => '10:30',
                'end' => '12:30',
                'max' => 8,
            ],
            [
                'category' => 'KINDER',
                'start' => '15:00',
                'end' => '17:00',
                'max' => 8,
            ],
        ];

        $rows = [];

        $id = 1;

        $period = CarbonPeriod::create(
            $validated['start_date'],
            $validated['end_date']
        );

        foreach ($period as $date) {

            if ($date->isWeekend()) {

                $rows[] = [
                    'id' => $id++,

                    'therapy_date' => $date->format('Y-m-d'),

                    'start_time' => null,

                    'end_time' => null,

                    'child_name' => null,

                    'program_category' => 'HOLIDAY',

                    'therapy_session_status_id' => null,
                ];

                continue;
            }

            foreach ($slots as $slot) {

                $count = rand(0, $slot['max']);

                for ($i = 0; $i < $count; $i++) {

                    $rows[] = [

                        'id' => $id++,

                        'therapy_date' => $date->format('Y-m-d'),

                        'start_time' => $slot['start'],

                        'end_time' => $slot['end'],

                        'child_name' => $names[array_rand($names)],

                        'program_category' => $slot['category'],

                        'therapy_session_status_id' => 1,

                    ];
                }
            }
        }

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function allowLateActivity($id)
    {
        $this->forbidNonAdmin();

        $session = TherapySession::findOrFail($id);

        $session->update([
            'allow_late_activity' => true,
        ]);

        return response()->json([
            'message' => 'Late activity allowed.',
        ]);
    }

    public function markAlpha(TherapySession $therapySession)
    {
        $this->forbidNonAdmin();

        if ($therapySession->therapy_session_status_id !== TherapySession::STATUS_SCHEDULED) {

            return response()->json([
                'message' => 'Only scheduled sessions can be marked as Alpha.',
            ], 422);
        }

        $therapySession->update([
            'therapy_session_status_id' => TherapySession::STATUS_ALPHA,
            'uses_session' => false,
        ]);

        return response()->json([
            'message' => 'Session marked as Alpha.',
            'data' => $therapySession->fresh([
                'therapist',
                'therapySessionStatus',
            ]),
        ]);
    }

    public function updateStatus(
        Request $request,
        TherapySession $therapySession
    ) {
        $this->forbidNonAdmin();

        $validated = $request->validate([
            'therapy_session_status_id' => [
                'required',
                'exists:therapy_session_statuses,id',
            ],
        ]);

        $therapySession->update([
            'therapy_session_status_id' => $validated['therapy_session_status_id'],
            'uses_session' => TherapySession::usesSessionForStatus(
                (int) $validated['therapy_session_status_id']
            ),
        ]);

        return response()->json([
            'message' => 'Attendance updated successfully.',
            'data' => $therapySession->fresh([
                'therapySessionStatus',
            ]),
        ]);
    }

    public function activityOptions(Request $request)
    {
        $validated = $request->validate([

            'program_category_id' => [
                'required',
                'exists:program_categories,id',
            ],

            'therapy_date' => [
                'nullable',
                'date',
            ],

        ]);

        $therapyDate = $validated['therapy_date']
            ?? now()->toDateString();

        $sessions = TherapySession::query()

            ->select([
                'therapy_date',
                'start_time',
                'end_time',
            ])

            ->whereDate(
                'therapy_date',
                $therapyDate
            )

            ->whereHas('registration.programs', function ($query) use ($validated) {

                $query->where(
                    'program_category_id',
                    $validated['program_category_id']
                );

            })

            ->groupBy(
                'therapy_date',
                'start_time',
                'end_time'
            )

            ->orderBy('therapy_date')

            ->orderBy('start_time')

            ->get();

        return response()->json([
            'data' => $sessions,
        ]);
    }

    public function activityChildren(Request $request)
    {
        $validated = $request->validate([

            'program_category_id' => [
                'required',
                'exists:program_categories,id',
            ],
        ]);

        $children = Child::query()
            ->where(
                'status_id',
                1
            )
            ->where(
                'program_category_id',
                $validated['program_category_id']
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'nickname',
            ]);

        return response()->json([
            'data' => $children,
        ]);
    }
}
