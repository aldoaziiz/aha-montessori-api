<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramCategorySessionTime;
use App\Models\ProgramTrack;
use App\Models\ProgramTrackClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProgramTrackController extends Controller
{
    public function index(ProgramCategory $programCategory)
    {
        abort_unless($programCategory->supports_tracks, 404);

        return response()->json([
            'data' => $programCategory->tracks()
                ->with([
                    'classes.sessionTimes',
                    'programs:id,program_track_id,name,session_count,price,status_id',
                ])
                ->orderBy('subject_name')
                ->orderBy('delivery_mode')
                ->get(),
        ]);
    }

    public function store(Request $request, ProgramCategory $programCategory)
    {
        abort_unless($programCategory->supports_tracks, 404);

        $validated = $request->validate([
            'subject_name' => [
                'required', 'string', 'max:100',
                Rule::unique('program_tracks', 'subject_name')
                    ->where('program_category_id', $programCategory->id)
                    ->where('delivery_mode', $request->input('delivery_mode')),
            ],
            'delivery_mode' => ['required', Rule::in(['regular', 'private'])],
            'price_per_session' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'classes' => ['required', 'array', 'min:1'],
            'classes.*.class_code' => ['required', 'string', 'max:30', 'distinct'],
            'classes.*.sessions' => ['required', 'array', 'min:1'],
            'classes.*.sessions.*.session_name' => ['required', 'string', 'max:100'],
            'classes.*.sessions.*.start_time' => ['required', 'date_format:H:i'],
            'classes.*.sessions.*.end_time' => ['required', 'date_format:H:i'],
            'classes.*.sessions.*.capacity' => ['required', 'integer', 'min:1'],
        ]);

        $this->validateClassSchedules($validated['classes']);

        $track = DB::transaction(function () use ($validated, $programCategory) {
            $track = ProgramTrack::create([
                'program_category_id' => $programCategory->id,
                'subject_name' => trim($validated['subject_name']),
                'delivery_mode' => $validated['delivery_mode'],
                'price_per_session' => $validated['price_per_session'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            foreach ($validated['classes'] as $class) {
                $this->createClassWithSessions($programCategory, $track, $class);
            }

            return $track->load('classes.sessionTimes');
        });

        return response()->json([
            'message' => 'Program track created successfully.',
            'data' => $track,
        ], 201);
    }

    public function update(Request $request, ProgramTrack $programTrack)
    {
        $validated = $request->validate([
            'subject_name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('program_tracks', 'subject_name')
                    ->where('program_category_id', $programTrack->program_category_id)
                    ->where('delivery_mode', $request->input('delivery_mode', $programTrack->delivery_mode))
                    ->ignore($programTrack->id),
            ],
            'delivery_mode' => ['sometimes', 'required', Rule::in(['regular', 'private'])],
            'price_per_session' => ['sometimes', 'required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $programTrack->update($validated);

        if (array_key_exists('price_per_session', $validated)) {
            $programTrack->programs()->get()->each(function (Program $program) use ($programTrack) {
                $program->update([
                    'price' => round((float) $programTrack->price_per_session * (int) $program->session_count),
                ]);
            });
        }

        return response()->json([
            'message' => 'Program track updated successfully.',
            'data' => $programTrack->fresh(['classes.sessionTimes', 'programs']),
        ]);
    }

    public function storeClass(Request $request, ProgramTrack $programTrack)
    {
        $validated = $request->validate([
            'class_code' => [
                'required', 'string', 'max:30',
                Rule::unique('program_track_classes', 'class_code')
                    ->where('program_track_id', $programTrack->id),
            ],
            'sessions' => ['required', 'array', 'min:1'],
            'sessions.*.session_name' => ['required', 'string', 'max:100'],
            'sessions.*.start_time' => ['required', 'date_format:H:i'],
            'sessions.*.end_time' => ['required', 'date_format:H:i'],
            'sessions.*.capacity' => ['required', 'integer', 'min:1'],
        ]);

        $this->validateClassSchedules([$validated]);

        $class = DB::transaction(function () use ($validated, $programTrack) {
            return $this->createClassWithSessions(
                $programTrack->category,
                $programTrack,
                $validated
            );
        });

        return response()->json([
            'message' => 'Program track class created successfully.',
            'data' => $class->load('sessionTimes'),
        ], 201);
    }

    public function updateClass(Request $request, ProgramTrackClass $programTrackClass)
    {
        $validated = $request->validate([
            'class_code' => [
                'sometimes', 'required', 'string', 'max:30',
                Rule::unique('program_track_classes', 'class_code')
                    ->where('program_track_id', $programTrackClass->program_track_id)
                    ->ignore($programTrackClass->id),
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $programTrackClass->update($validated);

        return response()->json([
            'message' => 'Program track class updated successfully.',
            'data' => $programTrackClass->fresh('sessionTimes'),
        ]);
    }

    private function createClassWithSessions(
        ProgramCategory $category,
        ProgramTrack $track,
        array $classData
    ): ProgramTrackClass {
        $class = ProgramTrackClass::create([
            'program_track_id' => $track->id,
            'class_code' => trim($classData['class_code']),
            'is_active' => true,
        ]);

        $nextOrder = ((int) ProgramCategorySessionTime::query()
            ->where('program_category_id', $category->id)
            ->max('session_order')) + 1;

        foreach ($classData['sessions'] as $session) {
            ProgramCategorySessionTime::create([
                'program_category_id' => $category->id,
                'program_track_class_id' => $class->id,
                'session_order' => $nextOrder++,
                'session_name' => trim($session['session_name']),
                'start_time' => $session['start_time'],
                'end_time' => $session['end_time'],
                'capacity' => $session['capacity'],
                'is_active' => true,
            ]);
        }

        return $class;
    }

    private function validateClassSchedules(array $classes): void
    {
        foreach ($classes as $classIndex => $class) {
            $sessions = collect($class['sessions'] ?? [])
                ->map(fn (array $session, int $index) => $session + ['index' => $index])
                ->sortBy('start_time')
                ->values();

            foreach ($sessions as $session) {
                if ($session['end_time'] <= $session['start_time']) {
                    throw ValidationException::withMessages([
                        "classes.$classIndex.sessions.{$session['index']}.end_time" => 'End time must be later than start time.',
                    ]);
                }
            }

            for ($index = 0; $index < $sessions->count() - 1; $index++) {
                if ($sessions[$index]['end_time'] > $sessions[$index + 1]['start_time']) {
                    throw ValidationException::withMessages([
                        "classes.$classIndex.sessions.{$sessions[$index]['index']}.end_time" => 'Session time overlaps with another session in this class.',
                        "classes.$classIndex.sessions.{$sessions[$index + 1]['index']}.start_time" => 'Session time overlaps with another session in this class.',
                    ]);
                }
            }
        }
    }
}
