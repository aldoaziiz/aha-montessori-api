<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategory;

class ProgramCategorySessionTimeController extends Controller
{
    public function index(ProgramCategory $programCategory)
    {
        return response()->json([
            'data' => $programCategory
                ->sessionTimes()
                ->with('trackClass.track')
                ->get([
                    'id',
                    'program_track_class_id',
                    'session_order',
                    'session_name',
                    'start_time',
                    'end_time',
                    'capacity',
                    'is_active',
                ])
                ->map(function ($sessionTime) {
                    return [
                        'id' => $sessionTime->id,
                        'program_track_class_id' => $sessionTime->program_track_class_id,
                        'session_order' => $sessionTime->session_order,
                        'session_name' => $sessionTime->session_name,
                        'start_time' => substr((string) $sessionTime->start_time, 0, 5),
                        'end_time' => substr((string) $sessionTime->end_time, 0, 5),
                        'capacity' => $sessionTime->capacity,
                        'is_active' => $sessionTime->is_active,
                        'track' => $sessionTime->trackClass ? [
                            'id' => $sessionTime->trackClass->track->id,
                            'subject_name' => $sessionTime->trackClass->track->subject_name,
                            'delivery_mode' => $sessionTime->trackClass->track->delivery_mode,
                            'is_active' => $sessionTime->trackClass->track->is_active,
                            'class_code' => $sessionTime->trackClass->class_code,
                            'class_is_active' => $sessionTime->trackClass->is_active,
                        ] : null,
                    ];
                }),
        ]);
    }
}
