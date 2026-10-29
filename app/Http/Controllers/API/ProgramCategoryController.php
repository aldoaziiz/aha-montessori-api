<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategory;
use App\Models\ProgramCategorySessionTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgramCategoryController extends Controller
{
    public function index()
    {
        $categories = ProgramCategory::query()
            ->select([
                'id',
                'name',
                'supports_tracks',
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $supportsTracks = $request->boolean('supports_tracks');

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('program_categories', 'name'),
            ],

            'supports_tracks' => ['sometimes', 'boolean'],

            'sessions' => [
                $supportsTracks ? 'nullable' : 'required',
                'array',
                ...($supportsTracks ? [] : ['min:1']),
            ],

            'sessions.*.session_order' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],

            'sessions.*.session_name' => [
                'required',
                'string',
                'max:100',
            ],

            'sessions.*.start_time' => [
                'required',
                'date_format:H:i',
            ],

            'sessions.*.end_time' => [
                'required',
                'date_format:H:i',
            ],

            'sessions.*.capacity' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $sessionsInput = $validated['sessions'] ?? [];

        foreach ($sessionsInput as $index => $session) {
            if ($session['end_time'] <= $session['start_time']) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        "sessions.$index.end_time" => [
                            'End time must be later than start time.',
                        ],
                    ],
                ], 422);
            }
        }

        $sessions = collect($sessionsInput)
            ->map(function ($session, $index) {
                $session['original_index'] = $index;

                return $session;
            })
            ->sortBy('start_time')
            ->values();

        for ($i = 0; $i < $sessions->count() - 1; $i++) {

            $current = $sessions[$i];
            $next = $sessions[$i + 1];

            if ($current['end_time'] > $next['start_time']) {

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        "sessions.{$current['original_index']}.end_time" => [
                            'Session time overlaps with another session.',
                        ],
                        "sessions.{$next['original_index']}.start_time" => [
                            'Session time overlaps with another session.',
                        ],
                    ],
                ], 422);
            }
        }

        $sortedSessions = collect($sessionsInput)
            ->sortBy('session_order')
            ->values()
            ->all();

        $category = DB::transaction(function () use ($validated, $sortedSessions, $supportsTracks) {
            $category = ProgramCategory::create([
                'name' => $validated['name'],
                'supports_tracks' => $supportsTracks,
            ]);

            foreach ($sortedSessions as $session) {
                ProgramCategorySessionTime::create([
                    'program_category_id' => $category->id,
                    'session_order' => $session['session_order'],
                    'session_name' => $session['session_name'],
                    'start_time' => $session['start_time'],
                    'end_time' => $session['end_time'],
                    'capacity' => $session['capacity'],
                    'is_active' => true,
                ]);
            }

            return $category;
        });

        return response()->json([
            'message' => 'Program category created successfully.',
            'data' => $category,
        ]);
    }
}
