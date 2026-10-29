<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategorySessionTime;
use App\Models\Registration;
use App\Models\RegistrationProgram;
use App\Models\SchoolScheduleSubmission;
use App\Models\TherapySession;
use App\Services\TherapySessionSlotAvailability;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolScheduleController extends Controller
{
    public function __construct(
        private readonly TherapySessionSlotAvailability $slotAvailability
    ) {}

    public function context(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'guardian') {
            abort(403, 'Forbidden');
        }

        $guardian = $user->guardian;

        if (! $guardian) {
            return $this->response([]);
        }

        $registrations = Registration::query()
            ->with([
                'child:id,name,nickname,session_started_at,session_expired_at',
                'programCategory:id,name,supports_tracks',
                'programs:id,program_category_id',
                'programs.category:id,name',
                'registrationPrograms.program.track',
                'registrationPrograms.therapySessions:id,registration_program_id,therapy_session_status_id,uses_session',
                'therapySessions:id,registration_id,therapy_session_status_id,uses_session',
            ])
            ->join('children', 'registrations.child_id', '=', 'children.id')
            ->whereHas('child.guardians', function ($query) use ($guardian) {
                $query->where('guardians.id', $guardian->id);
            })
            ->select('registrations.*')
            ->orderBy('children.name')
            ->orderByDesc('registrations.created_at')
            ->get()
            ->map(function (Registration $registration) {
                $summary = $registration->session_summary;
                $programCategoryId = $this->resolveProgramCategoryId($registration);
                $programCategory = $registration->programCategory
                    ?? $registration->programs
                        ->firstWhere('program_category_id', $programCategoryId)
                        ?->category;

                return [
                    'id' => $registration->id,
                    'registration_number' => $registration->registration_number,
                    'registration_status' => $registration->registration_status,
                    'registration_status_label' => $registration->registration_status_label,
                    'total_session' => $registration->total_session,
                    'session_started_at' => $registration->session_started_at?->toDateString(),
                    'session_expired_at' => $registration->session_expired_at?->toDateString(),
                    'child' => [
                        'id' => $registration->child->id,
                        'name' => $registration->child->name,
                        'nickname' => $registration->child->nickname,
                    ],
                    'program_category' => $programCategory ? [
                        'id' => $programCategory->id,
                        'name' => $programCategory->name,
                        'supports_tracks' => (bool) $programCategory->supports_tracks,
                    ] : null,
                    'program_selections' => $registration->registrationPrograms
                        ->filter(fn (RegistrationProgram $item) => $item->program?->track)
                        ->map(fn (RegistrationProgram $item) => $this->registrationProgramSummary($item))
                        ->values(),
                    'session_summary' => [
                        'total_session' => $summary['total_session'],
                        'used_session' => $summary['used_session'],
                        'remaining_session' => $summary['remaining_session'],
                        'created_session' => $summary['created_session'],
                        'scheduled_session' => $summary['scheduled_session'],
                        'completed_session' => $summary['completed_session'],
                        'available_to_schedule' => $summary['available_to_schedule'],
                        'is_session_expired' => $summary['is_session_expired'],
                    ],
                ];
            })
            ->values();

        return $this->response($registrations->all());
    }

    public function calendar(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'guardian') {
            abort(403, 'Forbidden');
        }

        $validated = $request->validate([
            'registration_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $guardian = $user->guardian;
        abort_if(! $guardian, 404, 'Registration not found.');

        $registration = Registration::query()
            ->with([
                'programs:id,program_category_id',
                'programCategory:id,name,supports_tracks',
                'registrationPrograms.program.track',
                'registrationPrograms.therapySessions',
                'therapySessions',
            ])
            ->whereKey($validated['registration_id'])
            ->whereHas('child.guardians', function ($query) use ($guardian) {
                $query->where('guardians.id', $guardian->id);
            })
            ->firstOrFail();

        $monthStart = Carbon::create(
            (int) $validated['year'],
            (int) $validated['month'],
            1
        )->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $currentMonth = now()->startOfMonth();
        $isPastMonth = $monthStart->lt($currentMonth);

        $programCategoryId = $this->resolveProgramCategoryId($registration);
        $trackBased = (bool) $registration->programCategory?->supports_tracks;
        $registrationPrograms = $registration->registrationPrograms
            ->filter(fn (RegistrationProgram $item) => $item->program?->track)
            ->keyBy('id');
        $trackIds = $registrationPrograms->pluck('program.program_track_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $allSessionTimes = ProgramCategorySessionTime::query()
            ->where('program_category_id', $programCategoryId)
            ->with('trackClass.track')
            ->when($trackBased, function ($query) use ($trackIds) {
                $query->whereHas('trackClass', function ($class) use ($trackIds) {
                    $class->where('is_active', true)
                        ->whereHas('track', fn ($track) => $track->where('is_active', true)->whereIn('id', $trackIds));
                });
            }, fn ($query) => $query->whereNull('program_track_class_id'))
            ->orderBy('session_order')
            ->get();
        $sessionTimes = $allSessionTimes
            ->where('is_active', true)
            ->values();

        $occupancySessions = TherapySession::query()
            ->whereBetween('therapy_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->get(['program_category_session_time_id', 'therapy_date', 'start_time', 'end_time']);
        $occupancy = $occupancySessions->countBy(fn (TherapySession $session) => $trackBased
            ? 'slot:'.$session->program_category_session_time_id
            : $this->slotKey(
                Carbon::parse($session->therapy_date)->toDateString(),
                $session->start_time,
                $session->end_time
            ));
        $childSessionsByDate = TherapySession::query()
            ->whereBetween('therapy_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereHas('registration', fn ($query) => $query->where('child_id', $registration->child_id))
            ->get(['therapy_date', 'start_time', 'end_time'])
            ->groupBy(fn (TherapySession $session) => Carbon::parse($session->therapy_date)->toDateString());

        $usedSessions = $registration->therapySessions
            ->where('uses_session', true)
            ->count();
        $scheduledSessionCount = $registration->therapySessions
            ->where('therapy_session_status_id', TherapySession::STATUS_SCHEDULED)
            ->count();
        $availableToSchedule = $trackBased
            ? $registrationPrograms->sum(fn (RegistrationProgram $item) => $this->registrationProgramSummary($item)['available_to_schedule'])
            : max(((int) $registration->total_session) - $usedSessions - $scheduledSessionCount, 0);

        $submission = SchoolScheduleSubmission::query()
            ->where('registration_id', $registration->id)
            ->where('year', (int) $validated['year'])
            ->where('month', (int) $validated['month'])
            ->first();

        $unavailableReason = $submission
            ? 'The schedule for this month has already been submitted.'
            : ($isPastMonth
                ? 'Past months are view-only. New schedules cannot be created.'
                : $this->schedulingUnavailableReason(
                    $registration,
                    $availableToSchedule,
                    $sessionTimes->isEmpty()
                ));
        $canSchedule = $unavailableReason === null;
        $existingRegistrationDates = $registration->therapySessions
            ->map(fn (TherapySession $session) => Carbon::parse($session->therapy_date)->toDateString())
            ->flip();
        $sessionTimesByTime = $allSessionTimes->keyBy(
            fn (ProgramCategorySessionTime $sessionTime) => $this->slotKey(
                'session-time',
                $sessionTime->start_time,
                $sessionTime->end_time
            )
        );
        $monthlySchedules = $registration->therapySessions
            ->filter(function (TherapySession $session) use ($monthStart, $monthEnd) {
                $date = Carbon::parse($session->therapy_date)->startOfDay();

                return $date->betweenIncluded($monthStart, $monthEnd);
            })
            ->sortBy(fn (TherapySession $session) => sprintf(
                '%s %s',
                Carbon::parse($session->therapy_date)->toDateString(),
                substr((string) $session->start_time, 0, 8)
            ))
            ->values()
            ->map(function (TherapySession $session) use ($sessionTimesByTime, $allSessionTimes) {
                $sessionTime = $session->program_category_session_time_id
                    ? $allSessionTimes->firstWhere('id', $session->program_category_session_time_id)
                    : $sessionTimesByTime->get($this->slotKey(
                    'session-time',
                    $session->start_time,
                    $session->end_time
                ));

                return [
                    'id' => $session->id,
                    'therapy_date' => Carbon::parse($session->therapy_date)->toDateString(),
                    'session_name' => $sessionTime?->session_name ?? 'School Session',
                    'subject_name' => $sessionTime?->trackClass?->track?->subject_name,
                    'delivery_mode' => $sessionTime?->trackClass?->track?->delivery_mode,
                    'class_code' => $sessionTime?->trackClass?->class_code,
                    'start_time' => substr((string) $session->start_time, 0, 5),
                    'end_time' => substr((string) $session->end_time, 0, 5),
                    'status' => [
                        'id' => (int) $session->therapy_session_status_id,
                        'name' => $this->therapySessionStatusLabel(
                            (int) $session->therapy_session_status_id
                        ),
                    ],
                ];
            });
        $dates = [];
        $selectableDates = [];

        for ($date = $monthStart->copy(); $date->lte($monthEnd); $date->addDay()) {
            $dateString = $date->toDateString();
            $sessions = $sessionTimes->map(function (ProgramCategorySessionTime $sessionTime) use (
                $occupancy,
                $dateString,
                $trackBased,
                $registrationPrograms,
                $childSessionsByDate
            ) {
                $track = $sessionTime->trackClass?->track;
                $registrationProgram = $trackBased
                    ? $registrationPrograms->first(fn (RegistrationProgram $item) =>
                        (int) $item->program?->program_track_id === (int) $track?->id
                    )
                    : null;
                $occupied = (int) $occupancy->get(
                    $trackBased
                        ? 'slot:'.$sessionTime->id
                        : $this->slotKey($dateString, $sessionTime->start_time, $sessionTime->end_time),
                    0
                );
                $capacity = max((int) $sessionTime->capacity, 0);
                $quota = $registrationProgram
                    ? $this->registrationProgramSummary($registrationProgram)['available_to_schedule']
                    : null;
                $hasChildConflict = $trackBased && $childSessionsByDate
                    ->get($dateString, collect())
                    ->contains(function (TherapySession $existing) use ($sessionTime) {
                        return (string) $existing->start_time < (string) $sessionTime->end_time
                            && (string) $existing->end_time > (string) $sessionTime->start_time;
                    });

                return [
                    'id' => $sessionTime->id,
                    'registration_program_id' => $registrationProgram?->id,
                    'track_id' => $track?->id,
                    'subject_name' => $track?->subject_name,
                    'delivery_mode' => $track?->delivery_mode,
                    'class_code' => $sessionTime->trackClass?->class_code,
                    'session_name' => $sessionTime->session_name,
                    'start_time' => substr((string) $sessionTime->start_time, 0, 5),
                    'end_time' => substr((string) $sessionTime->end_time, 0, 5),
                    'occupied' => $occupied,
                    'capacity' => $capacity,
                    'is_full' => $capacity <= 0 || $occupied >= $capacity,
                    'available_for_program' => ! $trackBased || ($registrationProgram !== null && $quota > 0),
                    'program_sessions_remaining' => $quota,
                    'has_child_time_conflict' => $hasChildConflict,
                ];
            })->values();

            $dateReason = null;
            if ($submission) {
                $dateReason = 'month_submitted';
            } elseif (! $canSchedule) {
                $dateReason = 'registration_unavailable';
            } elseif ($date->lt(now()->startOfDay())) {
                $dateReason = 'past_date';
            } elseif (! $trackBased && $existingRegistrationDates->has($dateString)) {
                $dateReason = 'already_scheduled';
            } elseif (! $sessions->contains(fn (array $session) =>
                ! $session['is_full']
                && $session['available_for_program']
                && ! $session['has_child_time_conflict']
            )) {
                $dateReason = 'no_session_available';
            }

            $isSelectable = $dateReason === null;
            if ($isSelectable) {
                $selectableDates[] = $dateString;
            }

            $dates[] = [
                'date' => $dateString,
                'is_selectable' => $isSelectable,
                'unavailable_reason' => $dateReason,
                'sessions' => $sessions->all(),
            ];
        }

        return response()->json([
            'data' => [
                'registration_id' => $registration->id,
                'year' => (int) $validated['year'],
                'month' => (int) $validated['month'],
                'can_schedule' => $canSchedule,
                'unavailable_reason' => $unavailableReason,
                'is_submitted' => $submission !== null,
                'submission' => $submission ? [
                    'id' => $submission->id,
                    'session_count' => $submission->session_count,
                    'submitted_at' => $submission->submitted_at?->toISOString(),
                ] : null,
                'available_to_schedule' => $availableToSchedule,
                'program_selections' => $registrationPrograms
                    ->map(fn (RegistrationProgram $item) => $this->registrationProgramSummary($item))
                    ->values(),
                'selectable_dates' => $selectableDates,
                'dates' => $dates,
                'scheduled_sessions' => $monthlySchedules->all(),
            ],
        ]);
    }

    public function submit(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'guardian') {
            abort(403, 'Forbidden');
        }

        $validated = $request->validate([
            'registration_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'sessions' => ['required', 'array', 'min:1'],
            'sessions.*.therapy_date' => ['required', 'date_format:Y-m-d'],
            'sessions.*.session_time_id' => [
                'required',
                'integer',
                'exists:program_category_session_times,id',
            ],
            'sessions.*.registration_program_id' => ['nullable', 'integer'],
        ]);

        $guardian = $user->guardian;
        abort_if(! $guardian, 404, 'Registration not found.');

        $result = DB::transaction(function () use ($validated, $guardian) {
            $registration = Registration::query()
                ->whereKey($validated['registration_id'])
                ->whereHas('child.guardians', function ($query) use ($guardian) {
                    $query->where('guardians.id', $guardian->id);
                })
                ->lockForUpdate()
                ->firstOrFail();
            $registration->load('programCategory');
            $trackBased = (bool) $registration->programCategory?->supports_tracks;
            $registrationPrograms = $registration->registrationPrograms()
                ->with(['program.track', 'therapySessions'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $registration->setRelation('registrationPrograms', $registrationPrograms->values());

            $monthStart = Carbon::create(
                (int) $validated['year'],
                (int) $validated['month'],
                1
            )->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

            $this->validateSubmissionMonth($registration, $monthStart, $monthEnd);

            $existingSubmission = SchoolScheduleSubmission::query()
                ->where('registration_id', $registration->id)
                ->where('year', (int) $validated['year'])
                ->where('month', (int) $validated['month'])
                ->lockForUpdate()
                ->first();

            if ($existingSubmission) {
                throw ValidationException::withMessages([
                    'month' => 'The schedule for this month has already been submitted.',
                ]);
            }

            $sessionTimeIds = collect($validated['sessions'])
                ->pluck('session_time_id')
                ->unique()
                ->values();
            $sessionTimes = ProgramCategorySessionTime::query()
                ->whereIn('id', $sessionTimeIds)
                ->with('trackClass.track')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $programCategoryId = $this->resolveProgramCategoryId($registration);

            $rows = collect($validated['sessions'])
                ->map(function (array $session, int $index) use (
                    $registration,
                    $programCategoryId,
                    $sessionTimes,
                    $registrationPrograms,
                    $trackBased,
                    $monthStart,
                    $monthEnd
                ) {
                    $field = "sessions.$index";
                    $date = Carbon::createFromFormat('Y-m-d', $session['therapy_date'])
                        ->startOfDay();

                    if ($date->lt(now()->startOfDay())) {
                        throw ValidationException::withMessages([
                            "$field.therapy_date" => 'Past dates cannot be selected.',
                        ]);
                    }
                    if (! $date->betweenIncluded($monthStart, $monthEnd)) {
                        throw ValidationException::withMessages([
                            "$field.therapy_date" => 'Every session date must be in the selected month.',
                        ]);
                    }
                    $sessionTime = $sessionTimes->get($session['session_time_id']);
                    if (
                        ! $sessionTime
                        || (int) $sessionTime->program_category_id !== $programCategoryId
                        || ! $sessionTime->is_active
                    ) {
                        throw ValidationException::withMessages([
                            "$field.session_time_id" => 'The selected session is unavailable for this program category.',
                        ]);
                    }

                    $registrationProgram = null;
                    if ($trackBased) {
                        $registrationProgram = $registrationPrograms->get(
                            (int) ($session['registration_program_id'] ?? 0)
                        );
                        $track = $sessionTime->trackClass?->track;
                        if (
                            ! $registrationProgram
                            || ! $track
                            || ! $sessionTime->trackClass->is_active
                            || ! $track->is_active
                            || (int) $registrationProgram->program?->program_track_id !== (int) $track->id
                        ) {
                            throw ValidationException::withMessages([
                                "$field.registration_program_id" => 'The selected package does not match this subject session.',
                            ]);
                        }
                    } elseif ($sessionTime->program_track_class_id) {
                        throw ValidationException::withMessages([
                            "$field.session_time_id" => 'A regular registration cannot use a subject track slot.',
                        ]);
                    }

                    return [
                        'row' => $index,
                        'therapy_date' => $date->toDateString(),
                        'session_time' => $sessionTime,
                        'registration_program' => $registrationProgram,
                    ];
                })
                ->values();

            if (! $trackBased && $rows->pluck('therapy_date')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'sessions' => 'Only one session per date is allowed for this program category.',
                ]);
            }

            $availableToSchedule = $trackBased
                ? $registrationPrograms->sum(fn (RegistrationProgram $item) => $this->registrationProgramSummary($item)['available_to_schedule'])
                : $this->availableSessionCount($registration, true);
            $unavailableReason = $this->schedulingUnavailableReason(
                $registration,
                $availableToSchedule,
                $sessionTimes->isEmpty()
            );
            if ($unavailableReason !== null) {
                throw ValidationException::withMessages([
                    'registration_id' => $unavailableReason,
                ]);
            }

            if ($rows->count() > $availableToSchedule) {
                throw ValidationException::withMessages([
                    'sessions' => 'Only '.$availableToSchedule.' sessions are available to schedule.',
                ]);
            }

            if ($trackBased) {
                foreach ($rows->groupBy(fn (array $row) => $row['registration_program']->id) as $registrationProgramId => $programRows) {
                    $program = $registrationPrograms->get((int) $registrationProgramId);
                    $remaining = $this->registrationProgramSummary($program)['available_to_schedule'];
                    if ($programRows->count() > $remaining) {
                        throw ValidationException::withMessages([
                            'sessions' => 'Only '.$remaining.' sessions remain for '.$program->program?->track?->subject_name.'.',
                        ]);
                    }
                }
            }

            $this->lockSubmittedSlotDefinitions($rows->all());

            $submittedDates = $rows->pluck('therapy_date')->all();
            $existingChildSessions = TherapySession::query()
                ->whereHas('registration', fn ($query) => $query->where('child_id', $registration->child_id))
                ->whereIn('therapy_date', $submittedDates)
                ->lockForUpdate()
                ->get(['registration_id', 'therapy_date', 'start_time', 'end_time']);

            $batchByDate = [];
            foreach ($rows as $row) {
                $field = 'sessions.'.$row['row'];
                $start = (string) $row['session_time']->start_time;
                $end = (string) $row['session_time']->end_time;

                if (! $trackBased) {
                    $alreadyOnDate = $existingChildSessions->contains(fn (TherapySession $existing) =>
                        (int) $existing->registration_id === (int) $registration->id
                        && Carbon::parse($existing->therapy_date)->toDateString() === $row['therapy_date']
                    );
                    if ($alreadyOnDate) {
                        throw ValidationException::withMessages([
                            "$field.therapy_date" => 'This child already has a session on this date.',
                        ]);
                    }
                } else {
                    $conflictsWithExisting = $existingChildSessions->contains(function (TherapySession $existing) use ($row, $start, $end) {
                        return Carbon::parse($existing->therapy_date)->toDateString() === $row['therapy_date']
                            && (string) $existing->start_time < $end
                            && (string) $existing->end_time > $start;
                    });
                    if ($conflictsWithExisting) {
                        throw ValidationException::withMessages([
                            "$field.session_time_id" => 'This child already has an overlapping session at the selected time.',
                        ]);
                    }

                    foreach ($batchByDate[$row['therapy_date']] ?? [] as [$otherStart, $otherEnd]) {
                        if ($start < $otherEnd && $end > $otherStart) {
                            throw ValidationException::withMessages([
                                "$field.session_time_id" => 'Two selected sessions for this child overlap.',
                            ]);
                        }
                    }
                    $batchByDate[$row['therapy_date']][] = [$start, $end];
                }
            }

            $slotCounts = [];
            foreach ($rows as $row) {
                $sessionTime = $row['session_time'];
                $slotKey = $trackBased
                    ? 'slot:'.$sessionTime->id
                    : $this->slotKey($row['therapy_date'], $sessionTime->start_time, $sessionTime->end_time);

                if (! array_key_exists($slotKey, $slotCounts)) {
                    $slotCounts[$slotKey] = $this->slotAvailability->countOccupancy(
                        $row['therapy_date'],
                        $sessionTime,
                        true
                    );
                }

                $capacity = max((int) $sessionTime->capacity, 0);
                if ($capacity <= 0 || $slotCounts[$slotKey] >= $capacity) {
                    throw ValidationException::withMessages([
                        'sessions.'.$row['row'].'.session_time_id' => 'This session slot is full.',
                    ]);
                }

                $slotCounts[$slotKey]++;
            }

            $submission = SchoolScheduleSubmission::create([
                'registration_id' => $registration->id,
                'guardian_id' => $guardian->id,
                'year' => (int) $validated['year'],
                'month' => (int) $validated['month'],
                'session_count' => $rows->count(),
                'submitted_at' => now(),
            ]);

            $sessions = $rows->map(function (array $row) use ($registration) {
                return TherapySession::create([
                    'registration_id' => $registration->id,
                    'registration_program_id' => $row['registration_program']?->id,
                    'program_category_session_time_id' => $row['session_time']->id,
                    'therapist_id' => null,
                    'therapy_session_status_id' => TherapySession::STATUS_SCHEDULED,
                    'uses_session' => false,
                    'therapy_date' => $row['therapy_date'],
                    'start_time' => $row['session_time']->start_time,
                    'end_time' => $row['session_time']->end_time,
                    'notes' => null,
                ]);
            });

            return [
                'submission' => $submission,
                'sessions' => $sessions,
            ];
        });

        return response()->json([
            'message' => 'The monthly school schedule was submitted successfully.',
            'data' => [
                'submission' => $result['submission'],
                'sessions' => $result['sessions'],
            ],
        ], 201);
    }

    private function validateSubmissionMonth(
        Registration $registration,
        Carbon $monthStart,
        Carbon $monthEnd
    ): void {
        if ($monthStart->lt(now()->startOfMonth())) {
            throw ValidationException::withMessages([
                'month' => 'Past months cannot be selected.',
            ]);
        }
    }

    private function availableSessionCount(Registration $registration, bool $lockForUpdate = false): int
    {
        if ($registration->total_session === null) {
            return 0;
        }

        $query = TherapySession::query()
            ->where('registration_id', $registration->id)
            ->where(function ($query) {
                $query->where('uses_session', true)
                    ->orWhere('therapy_session_status_id', TherapySession::STATUS_SCHEDULED);
            });

        $consumed = $lockForUpdate
            ? $query->lockForUpdate()->get(['id'])->count()
            : $query->count();

        return max(((int) $registration->total_session) - $consumed, 0);
    }

    private function lockSubmittedSlotDefinitions(array $rows): void
    {
        $this->slotAvailability->lockDefinitions($rows);
    }

    private function schedulingUnavailableReason(
        Registration $registration,
        int $availableToSchedule,
        bool $hasNoSessionTimes
    ): ?string {
        if (! $registration->canScheduleSessions()) {
            return $registration->registration_status === Registration::STATUS_PENDING
                ? 'This registration is pending admin review.'
                : 'This registration is not active.';
        }
        if ($registration->total_session === null) {
            return 'Session totals have not been set for this registration.';
        }
        if ($availableToSchedule <= 0) {
            return 'No sessions are available to schedule.';
        }
        if ($hasNoSessionTimes) {
            return 'No active session times are available for this program category.';
        }

        return null;
    }

    private function slotKey(string $date, string $startTime, string $endTime): string
    {
        return implode('|', [
            $date,
            substr($startTime, 0, 8),
            substr($endTime, 0, 8),
        ]);
    }

    private function resolveProgramCategoryId(Registration $registration): ?int
    {
        if ($registration->program_category_id) {
            return (int) $registration->program_category_id;
        }

        $categoryIds = $registration->relationLoaded('programs')
            ? $registration->programs->pluck('program_category_id')
            : $registration->programs()->pluck('programs.program_category_id');
        $categoryIds = $categoryIds
            ->filter()
            ->map(fn ($categoryId) => (int) $categoryId)
            ->unique()
            ->values();

        return $categoryIds->count() === 1
            ? $categoryIds->first()
            : null;
    }

    private function registrationProgramSummary(RegistrationProgram $item): array
    {
        $program = $item->program;
        $created = $item->therapySessions
            ->filter(fn (TherapySession $session) => $session->uses_session
                || $session->therapy_session_status_id === TherapySession::STATUS_SCHEDULED)
            ->count();
        $total = (int) ($program?->session_count ?? 0) * (int) $item->learning_period_months;

        return [
            'id' => (int) $item->id,
            'program_id' => (int) $item->program_id,
            'program_name' => $program?->name,
            'track_id' => (int) $program?->program_track_id,
            'subject_name' => $program?->track?->subject_name,
            'delivery_mode' => $program?->track?->delivery_mode,
            'session_count_per_month' => (int) ($program?->session_count ?? 0),
            'learning_period_months' => (int) $item->learning_period_months,
            'total_sessions' => $total,
            'created_sessions' => $created,
            'available_to_schedule' => max($total - $created, 0),
        ];
    }

    private function therapySessionStatusLabel(int $statusId): string
    {
        return match ($statusId) {
            TherapySession::STATUS_SCHEDULED => 'Scheduled',
            TherapySession::STATUS_COMPLETED => 'Completed',
            TherapySession::STATUS_ALPHA => 'Alpha',
            default => 'Unknown',
        };
    }

    private function response(array $registrations)
    {
        return response()->json([
            'data' => $registrations,
            'meta' => [
                'current_date' => now()->toDateString(),
            ],
        ]);
    }
}
