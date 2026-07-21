<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategorySessionTime;
use Illuminate\Http\Request;

class ProgramCategorySessionController extends Controller
{
    public function index(Request $request)
    {
        $query = ProgramCategorySessionTime::with('programCategory');

        if ($request->filled('search')) {
            $query->where(
                'session_name',
                'like',
                '%'.$request->search.'%'
            );
        }

        if ($request->filled('program_category_id')) {
            $query->where(
                'program_category_id',
                $request->program_category_id
            );
        }

        $sessionTimes = $query
            ->orderBy('program_category_id')
            ->orderBy('session_order')
            ->get();

        $data = $sessionTimes->map(function ($item) {
            return [
                'id' => $item->id,
                'program_category_id' => $item->program_category_id,
                'category_name' => $item->programCategory->name,
                'session_order' => $item->session_order,
                'session_name' => $item->session_name,
                'start_time' => substr($item->start_time, 0, 5),
                'end_time' => substr($item->end_time, 0, 5),
                'capacity' => $item->capacity,
                'is_active' => $item->is_active,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    public function update(
        Request $request,
        ProgramCategorySessionTime $programCategorySession
    ) {
        $validated = $request->validate([
            'session_order' => 'required|integer|min:1',
            'session_name' => 'required|string|max:100',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'capacity' => 'required|integer|min:1',
        ]);

        $programCategorySession->update($validated);

        return response()->json([
            'message' => 'Session updated successfully.',
            'data' => $programCategorySession->fresh(),
        ]);
    }

    public function toggleStatus(
        ProgramCategorySessionTime $programCategorySession
    ) {
        $programCategorySession->update([
            'is_active' => ! $programCategorySession->is_active,
        ]);

        return response()->json([
            'message' => $programCategorySession->is_active
                ? 'Session activated successfully.'
                : 'Session deactivated successfully.',
        ]);
    }
}
