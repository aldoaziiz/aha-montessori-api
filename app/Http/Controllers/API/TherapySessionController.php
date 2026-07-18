<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategorySessionTime;
use App\Models\Registration;
use App\Models\Staff;
use App\Models\TherapySession;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
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

        // filter therapy session status
        if ($request->filled('therapy_session_status_id')) {
            $query->where(
                'therapy_session_status_id',
                $request->therapy_session_status_id
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
            'therapist_id' => 'nullable|exists:staff,id',
            'therapy_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
            'notes' => 'nullable',
        ]);

        // validasi tanggal dan jam sama
        $duplicate = TherapySession::where(
            'registration_id',
            $request->registration_id
        )
            ->whereDate('therapy_date', $request->therapy_date)
            ->where('start_time', $request->start_time)
            ->where('end_time', $request->end_time)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'This session already exists.',
            ], 422);
        }

        // buat sesi terapi
        $session = TherapySession::create([
            'registration_id' => $request->registration_id,
            'therapy_session_status_id' => 1,
            'therapist_id' => null,
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
            'therapist_id' => 'nullable|exists:staff,id',
            'therapy_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
            'notes' => 'nullable|string',
        ]);

        $duplicate = TherapySession::where(
            'registration_id',
            $session->registration_id
        )
            ->where('id', '!=', $session->id)
            ->whereDate('therapy_date', $validated['therapy_date'])
            ->where('start_time', $validated['start_time'])
            ->where('end_time', $validated['end_time'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'This session already exists.',
            ], 422);
        }

        $session->update([

            'therapist_id' => null,

            'therapy_date' => $validated['therapy_date'],

            'start_time' => $validated['start_time'],

            'end_time' => $validated['end_time'],

            'notes' => $validated['notes'] ?? null,

        ]);

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

            'schedule_configs.*.session_time_id' => 'required|exists:program_category_session_times,id',
        ]);

        $registration = Registration::with([
            'programs.category',
        ])->findOrFail($validated['registration_id']);

        $totalSessions = $registration
            ->programs
            ->sum(function ($program) {

                return $program->session_count
                    * $program->pivot->learning_period_months;

            });

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

        $generatedSchedules = $this->generateSchedules(
            $validated['start_date'],
            $validated['schedule_configs'],
            $totalSessions
        );

        [
            'validSchedules' => $validSchedules,
            'conflicts' => $conflicts,
        ] = $this->checkConflicts(
            $generatedSchedules
        );

        if (! empty($conflicts)) {

            return response()->json([
                'message' => 'Some selected sessions are already full.',
                'conflicts' => $conflicts,
            ], 422);
        }

        $sessions = DB::transaction(function () use (
            $validated,
            $validSchedules
        ) {

            return $this->createSessions(
                $validated['registration_id'],
                $validated['notes'] ?? null,
                $validSchedules
            );
        });

        return response()->json([

            'message' => count($sessions).' sessions generated successfully.',

            'target_sessions' => $totalSessions,

            'generated_sessions' => count($sessions),

            'data' => $sessions,

        ]);
    }

    private function generateSchedules(
        string $startDate,
        array $scheduleConfigs,
        int $totalSessions
    ): array {

        $generatedSchedules = [];

        $currentDate = Carbon::parse($startDate);

        $sessionTimes = ProgramCategorySessionTime::whereIn(
            'id',
            collect($scheduleConfigs)->pluck('session_time_id')
        )->get()->keyBy('id');

        $scheduleConfigs = collect($scheduleConfigs)
            ->map(function ($config) use ($sessionTimes) {

                $config['session_time'] = $sessionTimes[$config['session_time_id']] ?? null;

                return $config;
            })
            ->values()
            ->all();

        while (count($generatedSchedules) < $totalSessions) {

            foreach ($scheduleConfigs as $config) {

                if ($currentDate->dayOfWeek !== $config['day']) {
                    continue;
                }

                $generatedSchedules[] = [

                    'therapy_date' => $currentDate->format('Y-m-d'),

                    'session_time' => $config['session_time'],

                ];

                break;
            }

            $currentDate->addDay();
        }

        return $generatedSchedules;
    }

    private function checkConflicts(array $generatedSchedules): array
    {
        $validSchedules = [];

        $conflicts = [];

        $slotCounts = [];

        $conflictKeys = [];

        foreach ($generatedSchedules as $schedule) {

            $sessionTime = $schedule['session_time'];

            $capacity = $sessionTime->capacity;

            $key = implode('|', [
                $schedule['therapy_date'],
                $sessionTime->start_time,
                $sessionTime->end_time,
            ]);

            // Query database hanya sekali untuk setiap slot
            if (! array_key_exists($key, $slotCounts)) {

                $slotCounts[$key] = TherapySession::whereDate(
                    'therapy_date',
                    $schedule['therapy_date']
                )
                    ->where('start_time', $sessionTime->start_time)
                    ->where('end_time', $sessionTime->end_time)
                    ->count();
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

                'therapist_id' => null,

                'therapy_session_status_id' => 1,

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

    public function grid(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $sessions = TherapySession::with([
            'registration.child',
            'registration.programs.category',
        ])
            ->whereBetween('therapy_date', [
                $validated['start_date'],
                $validated['end_date'],
            ])
            ->orderBy('therapy_date')
            ->orderBy('start_time')
            ->get();

        return $sessions->map(function ($session) {

            $therapyProgram = $session
                ->registration
                ->programs
                ->first(function ($program) {
                    return $program->session_count > 0;
                });

            return [

                'id' => $session->id,

                'therapy_date' => $session->therapy_date,

                'start_time' => substr($session->start_time, 0, 5),

                'end_time' => substr($session->end_time, 0, 5),

                'child_name' => $session->registration->child->name,

                'program_category' => optional(
                    $therapyProgram?->category
                )->name,

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
