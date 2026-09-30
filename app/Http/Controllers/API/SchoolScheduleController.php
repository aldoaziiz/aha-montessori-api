<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategorySessionTime;
use App\Models\Registration;
use App\Models\SchoolScheduleSubmission;
use App\Models\TherapySession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolScheduleController extends Controller
{
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
                'child:id,name,nickname',
                'programCategory:id,name',
                'programs:id,program_category_id',
                'programs.category:id,name',
                'therapySessions:id,registration_id,uses_session',
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
                    ] : null,
                    'session_summary' => [
                        'total_session' => $summary['total_session'],
                        'used_session' => $summary['used_session'],
                        'remaining_session' => $summary['remaining_session'],
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

        if ($monthStart->lt($currentMonth)) {
            throw ValidationException::withMessages([
                'month' => 'Past months cannot be selected.',
            ]);
        }

        $validFrom = $registration->session_started_at?->copy()->startOfDay();
        $validUntil = $registration->session_expired_at?->copy()->startOfDay();

        if ($validFrom && $monthEnd->lt($validFrom)) {
            throw ValidationException::withMessages([
                'month' => 'The selected month is before the registration validity period.',
            ]);
        }

        if ($validUntil && $monthStart->gt($validUntil)) {
            throw ValidationException::withMessages([
                'month' => 'The selected month is after the registration validity period.',
            ]);
        }

        $programCategoryId = $this->resolveProgramCategoryId($registration);
        $allSessionTimes = ProgramCategorySessionTime::query()
            ->where('program_category_id', $programCategoryId)
            ->orderBy('session_order')
            ->get();
        $sessionTimes = $allSessionTimes
            ->where('is_active', true)
            ->values();

        $occupancy = TherapySession::query()
            ->whereBetween('therapy_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->get(['therapy_date', 'start_time', 'end_time'])
            ->countBy(fn (TherapySession $session) => $this->slotKey(
                Carbon::parse($session->therapy_date)->toDateString(),
                $session->start_time,
                $session->end_time
            ));

        $usedSessions = $registration->therapySessions
            ->where('uses_session', true)
            ->count();
        $scheduledSessionCount = $registration->therapySessions
            ->where('therapy_session_status_id', TherapySession::STATUS_SCHEDULED)
            ->count();
        $availableToSchedule = max(
            ((int) $registration->total_session) - $usedSessions - $scheduledSessionCount,
            0
        );

        $submission = SchoolScheduleSubmission::query()
            ->where('registration_id', $registration->id)
            ->where('year', (int) $validated['year'])
            ->where('month', (int) $validated['month'])
            ->first();

        $unavailableReason = $submission
            ? 'The schedule for this month has already been submitted.'
            : $this->schedulingUnavailableReason(
                $registration,
                $availableToSchedule,
                $sessionTimes->isEmpty()
            );
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
            ->map(function (TherapySession $session) use ($sessionTimesByTime) {
                $sessionTime = $sessionTimesByTime->get($this->slotKey(
                    'session-time',
                    $session->start_time,
                    $session->end_time
                ));

                return [
                    'id' => $session->id,
                    'therapy_date' => Carbon::parse($session->therapy_date)->toDateString(),
                    'session_name' => $sessionTime?->session_name ?? 'School Session',
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
                $dateString
            ) {
                $occupied = (int) $occupancy->get(
                    $this->slotKey($dateString, $sessionTime->start_time, $sessionTime->end_time),
                    0
                );
                $capacity = max((int) $sessionTime->capacity, 0);

                return [
                    'id' => $sessionTime->id,
                    'session_name' => $sessionTime->session_name,
                    'start_time' => substr((string) $sessionTime->start_time, 0, 5),
                    'end_time' => substr((string) $sessionTime->end_time, 0, 5),
                    'occupied' => $occupied,
                    'capacity' => $capacity,
                    'is_full' => $capacity <= 0 || $occupied >= $capacity,
                ];
            })->values();

            $dateReason = null;
            if ($submission) {
                $dateReason = 'month_submitted';
            } elseif (! $canSchedule) {
                $dateReason = 'registration_unavailable';
            } elseif ($date->lt(now()->startOfDay())) {
                $dateReason = 'past_date';
            } elseif (($validFrom && $date->lt($validFrom)) || ($validUntil && $date->gt($validUntil))) {
                $dateReason = 'outside_validity';
            } elseif ($existingRegistrationDates->has($dateString)) {
                $dateReason = 'already_scheduled';
            } elseif (! $sessions->contains(fn (array $session) => ! $session['is_full'])) {
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
            'sessions.*.therapy_date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'sessions.*.session_time_id' => [
                'required',
                'integer',
                'exists:program_category_session_times,id',
            ],
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
                ->orderBy('id')
                ->get()
                ->keyBy('id');
            $programCategoryId = $this->resolveProgramCategoryId($registration);

            $rows = collect($validated['sessions'])
                ->map(function (array $session, int $index) use (
                    $registration,
                    $programCategoryId,
                    $sessionTimes,
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
                    if (! $date->betweenIncluded(
                        $registration->session_started_at,
                        $registration->session_expired_at
                    )) {
                        throw ValidationException::withMessages([
                            "$field.therapy_date" => 'This date is outside the registration validity period.',
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

                    return [
                        'row' => $index + 1,
                        'therapy_date' => $date->toDateString(),
                        'session_time' => $sessionTime,
                    ];
                })
                ->values();

            $availableToSchedule = $this->availableSessionCount($registration, true);
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

            $this->lockSubmittedSlotDefinitions($rows->all());

            $submittedDates = $rows->pluck('therapy_date')->all();
            $existingDates = TherapySession::query()
                ->where('registration_id', $registration->id)
                ->whereIn('therapy_date', $submittedDates)
                ->lockForUpdate()
                ->get(['therapy_date'])
                ->map(fn (TherapySession $session) => Carbon::parse($session->therapy_date)->toDateString())
                ->flip();

            foreach ($rows as $row) {
                if ($existingDates->has($row['therapy_date'])) {
                    throw ValidationException::withMessages([
                        'sessions.'.($row['row'] - 1).'.therapy_date' => 'This child already has a session on this date.',
                    ]);
                }
            }

            $slotCounts = [];
            foreach ($rows as $row) {
                $sessionTime = $row['session_time'];
                $slotKey = $this->slotKey(
                    $row['therapy_date'],
                    $sessionTime->start_time,
                    $sessionTime->end_time
                );

                if (! array_key_exists($slotKey, $slotCounts)) {
                    $slotCounts[$slotKey] = TherapySession::query()
                        ->whereDate('therapy_date', $row['therapy_date'])
                        ->whereTime('start_time', $sessionTime->start_time)
                        ->whereTime('end_time', $sessionTime->end_time)
                        ->lockForUpdate()
                        ->get(['id'])
                        ->count();
                }

                $capacity = max((int) $sessionTime->capacity, 0);
                if ($capacity <= 0 || $slotCounts[$slotKey] >= $capacity) {
                    throw ValidationException::withMessages([
                        'sessions.'.($row['row'] - 1).'.session_time_id' => 'This session slot is full.',
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
        if (! $registration->session_started_at || ! $registration->session_expired_at) {
            throw ValidationException::withMessages([
                'registration_id' => 'Session validity dates have not been set for this registration.',
            ]);
        }
        if ($monthEnd->lt($registration->session_started_at)) {
            throw ValidationException::withMessages([
                'month' => 'The selected month is before the registration validity period.',
            ]);
        }
        if ($monthStart->gt($registration->session_expired_at)) {
            throw ValidationException::withMessages([
                'month' => 'The selected month is after the registration validity period.',
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
        $slots = collect($rows)
            ->map(fn (array $row) => [
                'start_time' => $row['session_time']->start_time,
                'end_time' => $row['session_time']->end_time,
            ])
            ->unique(fn (array $slot) => $slot['start_time'].'|'.$slot['end_time'])
            ->values();

        ProgramCategorySessionTime::query()
            ->where(function ($query) use ($slots) {
                foreach ($slots as $slot) {
                    $query->orWhere(function ($query) use ($slot) {
                        $query->whereTime('start_time', $slot['start_time'])
                            ->whereTime('end_time', $slot['end_time']);
                    });
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function schedulingUnavailableReason(
        Registration $registration,
        int $availableToSchedule,
        bool $hasNoSessionTimes
    ): ?string {
        if (! $registration->canScheduleSessions()) {
            return 'This registration is not active.';
        }
        if ($registration->total_session === null) {
            return 'Session totals have not been set for this registration.';
        }
        if (! $registration->session_started_at || ! $registration->session_expired_at) {
            return 'Session validity dates have not been set for this registration.';
        }
        if ($registration->isSessionExpired()) {
            return 'Sessions for this registration have expired.';
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
