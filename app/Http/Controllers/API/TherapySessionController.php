<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\Staff;
use App\Models\TherapySession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TherapySessionController extends Controller
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
        $user = auth()->user();
        if (
            $user->role ===
            'guardian'
        ) {

            abort(
                403,
                'Forbidden'
            );

        }

        $query = TherapySession::with([
            'therapist.staffRole',
            'therapySessionStatus',
            'registration.child',
            'registration.programs',
            'activity.photos',
        ]);

        // ======================
        // THERAPIST FILTER
        // ======================

        if (
            $user->role ===
            'therapist'
        ) {

            $query->where(
                'therapist_id',
                $user->staff->id
            );

        }

        // ======================
        // THERAPIST TODAY ONLY
        // ======================

        if (
            $user->role ===
            'therapist' &&
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

        // ======================
        // WITHOUT ACTIVITY
        // ======================

        if ($request->without_activity) {

            $query->whereDoesntHave('activity');
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
                'data' => $query->get(),
            ]);
        }

        $data = $query->paginate(
            $request->per_page ?? 10
        );

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $this->forbidNonAdmin();

        $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'therapist_id' => 'required|exists:staff,id',
            'therapy_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required',

            'notes' => 'nullable',
        ]);

        //
        if ($request->start_time >= $request->end_time) {

            return response()->json([
                'message' => 'End time must be greater than start time.',
            ], 422);
        }

        $therapistConflict = TherapySession::where('therapist_id', $request->therapist_id)
            ->where('therapy_date', $request->therapy_date)
            ->where(function ($q) use ($request) {

                $q->where('start_time', '<', $request->end_time)
                    ->where('end_time', '>', $request->start_time);
            })
            ->exists();

        // validasi terapis sudah ada jadwal di waktu yang sama
        if ($therapistConflict) {
            return response()->json(['message' => 'Therapist is already booked for the selected time'], 422);
        }

        // buat sesi terapi
        $session = TherapySession::create([
            'registration_id' => $request->registration_id,
            'therapy_session_status_id' => 1,
            'therapist_id' => $request->therapist_id,
            'therapy_date' => $request->therapy_date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,

            'notes' => $request->notes,
        ]);

        return response()->json([
            'message' => 'Therapy session created',
            'data' => $session,
        ]);
    }

    public function show(string $id)
    {
        dd('masuk ke show');
    }

    public function update(Request $request, $id)
    {
        $this->forbidNonAdmin();

        $session = TherapySession::findOrFail($id);

        // LOCK
        if ($session->activity) {
            return response()->json([
                'message' => 'Completed sessions cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'therapist_id' => 'required|exists:staff,id',
            'therapy_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
            'notes' => 'nullable|string',
        ]);

        // therapist conflict
        $conflict = TherapySession::where(
            'therapist_id',
            $validated['therapist_id']
        )
            ->where('id', '!=', $session->id)
            ->whereDate(
                'therapy_date',
                $validated['therapy_date']
            )
            ->where(function ($query) use ($validated) {
                $query
                    ->where('start_time', '<', $validated['end_time'])
                    ->where('end_time', '>', $validated['start_time']);
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'message' => 'Therapist already has another session.',
            ], 422);
        }

        $session->update($validated);

        return response()->json([
            'message' => 'Session updated successfully.',
            'data' => $session->fresh([
                'therapist',
                'therapySessionStatus',
            ]),
        ]);
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

            'start_date' => 'required|date',

            'notes' => 'nullable|string',

            'schedule_configs' => 'required|array|min:1',

            'schedule_configs.*.day' => 'required|integer|between:0,6',

            'schedule_configs.*.therapist_id' => 'required|exists:staff,id',

            'schedule_configs.*.time_slot' => 'required|string',
        ]);

        $registration = Registration::with(
            'programs'
        )->findOrFail(
            $validated['registration_id']
        );

        $totalSessions = $registration
            ->programs
            ->sum('session_count');

        if ($totalSessions <= 0) {

            return response()->json([
                'message' => 'Selected programs do not have sessions.',
            ], 422);
        }

        if (
            TherapySession::where(
                'registration_id',
                $registration->id
            )->exists()
        ) {

            return response()->json([
                'message' => 'Sessions have already been generated.',
            ], 422);
        }

        return DB::transaction(function () use (
            $validated,
            $totalSessions
        ) {

            $generatedSchedules = [];

            $currentDate = Carbon::parse($validated['start_date']);

            while (count($generatedSchedules) < $totalSessions) {

                foreach ($validated['schedule_configs'] as $config) {

                    if (
                        $currentDate->dayOfWeek === $config['day']
                    ) {

                        $generatedSchedules[] = [
                            'date' => $currentDate->copy(),
                            'therapist_id' => $config['therapist_id'],
                            'time_slot' => $config['time_slot'],
                        ];

                        break;
                    }
                }

                $currentDate->addDay();
            }

            // ======================
            // CHECK CONFLICTS
            // ======================

            $conflicts = [];

            foreach ($generatedSchedules as $schedule) {

                [$startTime, $endTime] = array_map(
                    'trim',
                    explode('-', $schedule['time_slot'])
                );

                $conflictSession = TherapySession::with([
                    'therapist',
                    'registration.child',
                ])
                    ->where(
                        'therapist_id',
                        $schedule['therapist_id']
                    )
                    ->whereDate(
                        'therapy_date',
                        $schedule['date']
                    )
                    ->where(function ($query) use ($startTime, $endTime) {

                        $query
                            ->where('start_time', '<', $endTime)
                            ->where('end_time', '>', $startTime);
                    })
                    ->first();

                if ($conflictSession) {

                    $conflicts[] = [

                        'therapy_date' => $schedule['date']->format('Y-m-d'),

                        'time_slot' => $schedule['time_slot'],

                        'therapist_name' => $conflictSession->therapist->name,

                        'child_name' => $conflictSession->registration->child->name,

                    ];
                }
            }

            if (! empty($conflicts)) {

                return response()->json([
                    'message' => 'Therapist conflict detected.',

                    'conflicts' => $conflicts,
                ], 422);
            }

            // ======================
            // CREATE SESSIONS
            // ======================

            $sessions = [];

            foreach ($generatedSchedules as $schedule) {

                [$startTime, $endTime] = array_map(
                    'trim',
                    explode('-', $schedule['time_slot'])
                );

                $sessions[] = TherapySession::create([

                    'registration_id' => $validated['registration_id'],

                    'therapist_id' => $schedule['therapist_id'],

                    'therapy_session_status_id' => 1,

                    'therapy_date' => $schedule['date']->format('Y-m-d'),

                    'start_time' => $startTime,

                    'end_time' => $endTime,

                    'notes' => $validated['notes'] ?? null,
                ]);
            }

            return response()->json([
                'message' => count($sessions)
                    .' sessions generated successfully.',

                'target_sessions' => $totalSessions,

                'data' => $sessions,
            ]);
        });
    }

    public function availability(Request $request)
    {

        $startDate = $request->start_date;

        $endDate = $request->end_date;

        $therapistId = $request->therapist_id;

        $therapists = Staff::query()
            ->whereHas('staffRole', function ($q) {

                $q->where(
                    'name',
                    'Therapist'
                );

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

        if ($therapySession->therapy_session_status_id !== 1) {

            return response()->json([
                'message' => 'Only scheduled sessions can be marked as Alpha.',
            ], 422);
        }

        $therapySession->update([
            'therapy_session_status_id' => 3,
        ]);

        return response()->json([
            'message' => 'Session marked as Alpha.',
            'data' => $therapySession->fresh([
                'therapist',
                'therapySessionStatus',
            ]),
        ]);
    }
}
