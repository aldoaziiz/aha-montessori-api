<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategorySessionTime;
use App\Models\TherapySession;
use App\Services\TherapySessionSlotAvailability;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramCategorySessionController extends Controller
{
    public function __construct(
        private readonly TherapySessionSlotAvailability $slotAvailability
    ) {}

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

        return DB::transaction(function () use ($programCategorySession, $validated) {
            $sessionTime = ProgramCategorySessionTime::query()
                ->lockForUpdate()
                ->findOrFail($programCategorySession->id);
            $oldStart = Carbon::parse($sessionTime->start_time)->format('H:i:s');
            $oldEnd = Carbon::parse($sessionTime->end_time)->format('H:i:s');
            $newStart = Carbon::parse($validated['start_time'])->format('H:i:s');
            $newEnd = Carbon::parse($validated['end_time'])->format('H:i:s');
            $timeChanged = $oldStart !== $newStart || $oldEnd !== $newEnd;

            $overlappingDefinition = ProgramCategorySessionTime::query()
                ->where('program_category_id', $sessionTime->program_category_id)
                ->where('id', '!=', $sessionTime->id)
                ->whereTime('start_time', '<', $newEnd)
                ->whereTime('end_time', '>', $newStart)
                ->lockForUpdate()
                ->exists();

            if ($overlappingDefinition) {
                throw ValidationException::withMessages([
                    'start_time' => 'Session time overlaps with another session in this category.',
                ]);
            }

            $candidate = clone $sessionTime;
            $candidate->start_time = $newStart;
            $candidate->end_time = $newEnd;
            $this->slotAvailability->lockDefinitions([
                ['session_time' => $sessionTime],
                ['session_time' => $candidate],
            ]);

            if ($timeChanged) {
                $futureSessionsUseOldTime = TherapySession::query()
                    ->whereDate('therapy_date', '>=', now()->toDateString())
                    ->whereTime('start_time', $oldStart)
                    ->whereTime('end_time', $oldEnd)
                    ->whereHas('registration', function ($registration) use ($sessionTime) {
                        $categoryId = (int) $sessionTime->program_category_id;
                        $registration->where('program_category_id', $categoryId)
                            ->orWhere(function ($legacy) use ($categoryId) {
                                $legacy->whereNull('program_category_id')
                                    ->whereHas('programs', fn ($program) => $program->where('program_category_id', $categoryId))
                                    ->whereDoesntHave('programs', fn ($program) => $program->where('program_category_id', '!=', $categoryId));
                            });
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($futureSessionsUseOldTime) {
                    throw ValidationException::withMessages([
                        'start_time' => 'Session times cannot be changed while future sessions are scheduled in this slot.',
                    ]);
                }
            }

            $overCapacity = TherapySession::query()
                ->selectRaw('DATE(therapy_date) as session_date, COUNT(*) as occupied')
                ->whereDate('therapy_date', '>=', now()->toDateString())
                ->whereTime('start_time', $newStart)
                ->whereTime('end_time', $newEnd)
                ->whereHas('registration', function ($registration) use ($sessionTime) {
                    $categoryId = (int) $sessionTime->program_category_id;
                    $registration->where('program_category_id', $categoryId)
                        ->orWhere(function ($legacy) use ($categoryId) {
                            $legacy->whereNull('program_category_id')
                                ->whereHas('programs', fn ($program) => $program->where('program_category_id', $categoryId))
                                ->whereDoesntHave('programs', fn ($program) => $program->where('program_category_id', '!=', $categoryId));
                        });
                })
                ->groupByRaw('DATE(therapy_date)')
                ->havingRaw('COUNT(*) > ?', [(int) $validated['capacity']])
                ->exists();

            if ($overCapacity) {
                throw ValidationException::withMessages([
                    'capacity' => 'The new capacity is below the number of sessions already scheduled in this slot.',
                ]);
            }

            $sessionTime->update($validated);

            return response()->json([
                'message' => 'Session updated successfully.',
                'data' => $sessionTime->fresh(),
            ]);
        }, 3);
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
