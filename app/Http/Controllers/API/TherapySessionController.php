<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Child;
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

        $validated = $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'session_time_id' => 'nullable|exists:program_category_session_times,id',
            'therapist_id' => 'nullable|exists:staff,id',
            'therapy_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
            'notes' => 'nullable',
        ]);

        return DB::transaction(function () use ($validated) {
            $registration = Registration::with('programs')->lockForUpdate()->findOrFail(
                $validated['registration_id']
            );

            $sessionTime = $this->resolveManualSessionTime(
                $registration,
                $validated,
                true
            );

            $row = [
                'row' => 1,
                'therapy_date' => Carbon::parse($validated['therapy_date'])->format('Y-m-d'),
                'session_time' => $sessionTime,
                'notes' => $validated['notes'] ?? null,
            ];

            $this->lockRelatedSessionTimeSlots([$row]);

            $conflicts = $this->getSessionCreateConflicts(
                (int) $registration->id,
                [$row]
            );

            if (! empty($conflicts)) {
                return response()->json([
                    'message' => $conflicts[0]['message'],
                    'conflicts' => $conflicts,
                ], 422);
            }

            // buat sesi terapi
            $session = TherapySession::create([
                'registration_id' => $registration->id,
                'therapy_session_status_id' => 1,
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

        $rows = $this->prepareBulkSessionRows($validated);

        $conflicts = $this->getSessionCreateConflicts(
            (int) $validated['registration_id'],
            $rows
        );

        return response()->json([
            'valid' => empty($conflicts),
            'message' => empty($conflicts)
                ? 'All sessions are available.'
                : 'Some sessions need attention.',
            'conflicts' => $conflicts,
        ], empty($conflicts) ? 200 : 422);
    }

    public function bulkStore(Request $request)
    {
        $this->forbidNonAdmin();

        $validated = $this->validateBulkSessionRequest($request);

        $result = DB::transaction(function () use ($validated) {
            Registration::lockForUpdate()->findOrFail($validated['registration_id']);

            $rows = $this->prepareBulkSessionRows($validated, true);

            $this->lockRelatedSessionTimeSlots($rows);

            $conflicts = $this->getSessionCreateConflicts(
                (int) $validated['registration_id'],
                $rows
            );

            if (! empty($conflicts)) {
                return [
                    'conflicts' => $conflicts,
                    'sessions' => [],
                ];
            }

            $sessions = [];

            foreach ($rows as $row) {
                $sessions[] = TherapySession::create([
                    'registration_id' => $validated['registration_id'],
                    'therapist_id' => null,
                    'therapy_session_status_id' => 1,
                    'therapy_date' => $row['therapy_date'],
                    'start_time' => $row['session_time']->start_time,
                    'end_time' => $row['session_time']->end_time,
                    'notes' => $row['notes'],
                ]);
            }

            return [
                'conflicts' => [],
                'sessions' => $sessions,
            ];
        });

        if (! empty($result['conflicts'])) {
            return response()->json([
                'message' => 'Some sessions are no longer available.',
                'conflicts' => $result['conflicts'],
            ], 422);
        }

        return response()->json([
            'message' => count($result['sessions']).' sessions created successfully.',
            'data' => $result['sessions'],
        ]);
    }

    private function validateBulkSessionRequest(Request $request): array
    {
        return $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'sessions' => 'required|array|min:1',
            'sessions.*.therapy_date' => 'required|date',
            'sessions.*.session_time_id' => 'required|exists:program_category_session_times,id',
            'sessions.*.notes' => 'nullable|string',
        ]);
    }

    private function prepareBulkSessionRows(array $validated, bool $lockSessionTimes = false): array
    {
        $sessionTimeIds = collect($validated['sessions'])
            ->pluck('session_time_id')
            ->unique()
            ->values()
            ->all();

        $sessionTimesQuery = ProgramCategorySessionTime::whereIn('id', $sessionTimeIds);

        if ($lockSessionTimes) {
            $sessionTimesQuery->lockForUpdate();
        }

        $sessionTimes = $sessionTimesQuery->get()->keyBy('id');

        return collect($validated['sessions'])
            ->map(function ($row, $index) use ($sessionTimes) {
                $sessionTime = $sessionTimes->get($row['session_time_id']);

                if (! $sessionTime) {
                    abort(422, 'Selected session time is invalid.');
                }

                return [
                    'row' => $index + 1,
                    'therapy_date' => Carbon::parse($row['therapy_date'])->format('Y-m-d'),
                    'session_time' => $sessionTime,
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
        $sessionTimeQuery = ProgramCategorySessionTime::query();

        if ($lockSessionTime) {
            $sessionTimeQuery->lockForUpdate();
        }

        if (! empty($validated['session_time_id'])) {
            return $sessionTimeQuery->findOrFail($validated['session_time_id']);
        }

        $programCategoryId = $registration->programs->first()?->program_category_id;

        if (! $programCategoryId) {
            abort(422, 'Program category is required to resolve session time.');
        }

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

    private function getSessionCreateConflicts(int $registrationId, array $rows): array
    {
        $conflicts = [];
        $slotCounts = [];
        $submittedRegistrationSlots = [];

        foreach ($rows as $row) {
            $sessionTime = $row['session_time'];
            $slotKey = $this->makeSessionSlotKey(
                $row['therapy_date'],
                $sessionTime->start_time,
                $sessionTime->end_time
            );

            if (isset($submittedRegistrationSlots[$slotKey])) {
                $conflicts[] = $this->makeSessionConflict(
                    $row,
                    'duplicate_submission',
                    'This session is duplicated in this submission.'
                );

                continue;
            }

            $submittedRegistrationSlots[$slotKey] = true;

            $duplicate = TherapySession::where('registration_id', $registrationId)
                ->whereDate('therapy_date', $row['therapy_date'])
                ->whereTime('start_time', $sessionTime->start_time)
                ->whereTime('end_time', $sessionTime->end_time)
                ->exists();

            if ($duplicate) {
                $conflicts[] = $this->makeSessionConflict(
                    $row,
                    'duplicate_existing',
                    'This session already exists for this child.'
                );

                continue;
            }

            if (! array_key_exists($slotKey, $slotCounts)) {
                $slotCounts[$slotKey] = TherapySession::whereDate(
                    'therapy_date',
                    $row['therapy_date']
                )
                    ->whereTime('start_time', $sessionTime->start_time)
                    ->whereTime('end_time', $sessionTime->end_time)
                    ->count();
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

        $result = DB::transaction(function () use (
            $validated,
            $generatedSchedules
        ) {
            $this->lockSessionTimesForSchedules($generatedSchedules);

            [
                'validSchedules' => $validSchedules,
                'conflicts' => $conflicts,
            ] = $this->checkConflicts(
                $generatedSchedules
            );

            if (! empty($conflicts)) {
                return [
                    'conflicts' => $conflicts,
                    'sessions' => [],
                ];
            }

            return [
                'conflicts' => [],
                'sessions' => $this->createSessions(
                    $validated['registration_id'],
                    $validated['notes'] ?? null,
                    $validSchedules
                ),
            ];
        });

        if (! empty($result['conflicts'])) {

            return response()->json([
                'message' => 'Some selected sessions are already full.',
                'conflicts' => $result['conflicts'],
            ], 422);
        }

        $sessions = $result['sessions'];

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
        $lockedSlotKeys = [];

        foreach ($rows as $row) {
            $sessionTime = $row['session_time'];
            $slotKey = $this->makeSessionSlotKey(
                '1970-01-01',
                $sessionTime->start_time,
                $sessionTime->end_time
            );

            if (isset($lockedSlotKeys[$slotKey])) {
                continue;
            }

            $lockedSlotKeys[$slotKey] = true;

            ProgramCategorySessionTime::whereTime('start_time', $sessionTime->start_time)
                ->whereTime('end_time', $sessionTime->end_time)
                ->lockForUpdate()
                ->get();
        }
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
                    ->whereTime('start_time', $sessionTime->start_time)
                    ->whereTime('end_time', $sessionTime->end_time)
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
