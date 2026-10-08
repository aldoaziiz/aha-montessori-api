<?php

namespace App\Services;

use App\Models\ProgramCategorySessionTime;
use App\Models\Registration;
use App\Models\TherapySession;
use Illuminate\Validation\ValidationException;

class TherapySessionSlotAvailability
{
    public function categoryIdFor(Registration $registration, string $field = 'session_time_id'): int
    {
        if ($registration->program_category_id) {
            return (int) $registration->program_category_id;
        }

        $categoryIds = $registration->programs()
            ->pluck('program_category_id')
            ->filter()
            ->unique()
            ->values();

        if ($categoryIds->count() !== 1) {
            throw ValidationException::withMessages([
                $field => 'Program category is required to resolve the selected session slot.',
            ]);
        }

        return (int) $categoryIds->first();
    }

    public function assertSlotUsableForRegistration(
        Registration $registration,
        ProgramCategorySessionTime $sessionTime,
        string $field
    ): void {
        $categoryId = $this->categoryIdFor($registration, $field);
        if ((int) $sessionTime->program_category_id !== $categoryId) {
            throw ValidationException::withMessages([
                $field => 'The selected session is unavailable for this program category.',
            ]);
        }

        if (! $sessionTime->is_active) {
            throw ValidationException::withMessages([
                $field => 'The selected session is inactive.',
            ]);
        }

        $ambiguousSlot = ProgramCategorySessionTime::query()
            ->where('program_category_id', $categoryId)
            ->where('id', '!=', $sessionTime->id)
            ->whereTime('start_time', $sessionTime->start_time)
            ->whereTime('end_time', $sessionTime->end_time)
            ->exists();

        if ($ambiguousSlot) {
            throw ValidationException::withMessages([
                $field => 'The selected session time is ambiguous for this program category.',
            ]);
        }
    }

    /** @param array<int, array{session_time: ProgramCategorySessionTime}> $rows */
    public function lockDefinitions(array $rows): void
    {
        $slots = collect($rows)
            ->map(fn (array $row) => $row['session_time'])
            ->unique(fn (ProgramCategorySessionTime $slot) => implode('|', [
                $slot->program_category_id,
                $slot->start_time,
                $slot->end_time,
            ]))
            ->values();

        if ($slots->isEmpty()) {
            return;
        }

        ProgramCategorySessionTime::query()
            ->where(function ($query) use ($slots) {
                foreach ($slots as $slot) {
                    $query->orWhere(function ($query) use ($slot) {
                        $query->where('program_category_id', $slot->program_category_id)
                            ->whereTime('start_time', $slot->start_time)
                            ->whereTime('end_time', $slot->end_time);
                    });
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    public function countOccupancy(
        string $date,
        ProgramCategorySessionTime $sessionTime,
        bool $lockForUpdate = false,
        ?int $excludeSessionId = null
    ): int {
        $query = TherapySession::query()
            ->whereDate('therapy_date', $date)
            ->whereTime('start_time', $sessionTime->start_time)
            ->whereTime('end_time', $sessionTime->end_time)
            ->whereHas('registration', function ($registration) use ($sessionTime) {
                $categoryId = (int) $sessionTime->program_category_id;
                $registration->where('program_category_id', $categoryId)
                    ->orWhere(function ($legacy) use ($categoryId) {
                        $legacy->whereNull('program_category_id')
                            ->whereHas('programs', fn ($program) => $program->where('program_category_id', $categoryId))
                            ->whereDoesntHave('programs', fn ($program) => $program->where('program_category_id', '!=', $categoryId));
                    });
            });

        if ($excludeSessionId !== null) {
            $query->where('id', '!=', $excludeSessionId);
        }

        return $lockForUpdate
            ? $query->lockForUpdate()->get(['id'])->count()
            : $query->count();
    }
}
