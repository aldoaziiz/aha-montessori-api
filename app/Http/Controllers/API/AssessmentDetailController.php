<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AssessmentDetail;
use App\Models\ProgramCategory;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssessmentDetailController extends Controller
{
    public function updateAppointment(Request $request, Registration $registration)
    {
        $this->forbidNonAdmin();
        $this->ensureExternallyScheduled($registration);

        $validated = $request->validate([
            'appointment_date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ]);

        $values = [
            'appointment_date' => $validated['appointment_date'] ?? null,
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
        ];
        $hasAnyValue = collect($values)->contains(fn ($value) => $value !== null && $value !== '');

        if ($hasAnyValue && in_array(null, $values, true)) {
            throw ValidationException::withMessages([
                'appointment_date' => ['Date, start time, and end time must all be provided together.'],
            ]);
        }

        if ($hasAnyValue && $values['end_time'] <= $values['start_time']) {
            throw ValidationException::withMessages([
                'end_time' => ['End time must be later than start time.'],
            ]);
        }

        $detail = DB::transaction(function () use ($registration, $values, $hasAnyValue) {
            $detail = AssessmentDetail::query()
                ->where('registration_id', $registration->id)
                ->lockForUpdate()
                ->first();

            if (! $detail && ! $hasAnyValue) {
                return null;
            }

            $detail ??= new AssessmentDetail(['registration_id' => $registration->id]);
            $detail->fill($values);

            if (! $hasAnyValue && ! $detail->report_path) {
                if ($detail->exists) {
                    $detail->delete();
                }

                return null;
            }

            $detail->save();

            return $detail->fresh('reportUploader');
        });

        return response()->json([
            'message' => 'Assessment appointment updated successfully.',
            'data' => $this->detailData($detail),
        ]);
    }

    public function uploadReport(Request $request, Registration $registration)
    {
        $this->forbidNonAdmin();
        $this->ensureExternallyScheduled($registration);

        $validated = $request->validate([
            'report' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $diskName = (string) config('filesystems.default', 'local');
        if ($diskName === 'public') {
            // Keep report files out of the publicly served storage symlink in local development.
            $diskName = 'local';
        }

        $disk = Storage::disk($diskName);
        $file = $validated['report'];
        $originalName = basename($file->getClientOriginalName());
        $directory = 'assessment-reports/'.$registration->id;
        $path = $disk->putFileAs(
            $directory,
            $file,
            Str::uuid().'.pdf'
        );

        if (! $path) {
            return response()->json([
                'message' => 'Failed to store the assessment report.',
            ], 500);
        }

        $previousFile = null;

        try {
            $detail = DB::transaction(function () use (
                $registration,
                $diskName,
                $path,
                $originalName,
                &$previousFile
            ) {
                $detail = AssessmentDetail::query()
                    ->where('registration_id', $registration->id)
                    ->lockForUpdate()
                    ->first();

                if (! $detail) {
                    $detail = new AssessmentDetail(['registration_id' => $registration->id]);
                } elseif ($detail->report_path) {
                    $previousFile = [
                        'disk' => $detail->report_disk ?: $diskName,
                        'path' => $detail->report_path,
                    ];
                }

                $detail->fill([
                    'report_disk' => $diskName,
                    'report_path' => $path,
                    'report_original_name' => $originalName,
                    'report_uploaded_by' => auth()->id(),
                    'report_uploaded_at' => now(),
                ]);
                $detail->save();

                return $detail->fresh('reportUploader');
            });
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        if ($previousFile) {
            Storage::disk($previousFile['disk'])->delete($previousFile['path']);
        }

        return response()->json([
            'message' => 'Assessment report uploaded successfully.',
            'data' => $this->detailData($detail),
        ]);
    }

    public function downloadReport(Registration $registration)
    {
        $this->forbidNonAdmin();
        $this->ensureExternallyScheduled($registration);

        $detail = AssessmentDetail::query()
            ->where('registration_id', $registration->id)
            ->firstOrFail();

        abort_if(! $detail->report_disk || ! $detail->report_path, 404, 'Assessment report not found.');

        $disk = Storage::disk($detail->report_disk);
        abort_unless($disk->exists($detail->report_path), 404, 'Assessment report not found.');

        return $disk->download(
            $detail->report_path,
            $detail->report_original_name ?: 'assessment-report.pdf',
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function forbidNonAdmin(): void
    {
        if (auth()->user()?->role !== 'admin') {
            abort(403, 'Forbidden');
        }
    }

    private function ensureExternallyScheduled(Registration $registration): void
    {
        $registration->loadMissing(['programCategory', 'programs.category']);
        $category = $registration->programCategory
            ?? $registration->programs->first()?->category;

        abort_unless(
            $category?->schedule_mode === ProgramCategory::SCHEDULE_EXTERNAL,
            422,
            'This registration does not use external assessment scheduling.'
        );
    }

    private function detailData(?AssessmentDetail $detail): ?array
    {
        if (! $detail) {
            return null;
        }

        return [
            'appointment_date' => $detail->appointment_date?->format('Y-m-d'),
            'start_time' => $detail->start_time ? substr((string) $detail->start_time, 0, 5) : null,
            'end_time' => $detail->end_time ? substr((string) $detail->end_time, 0, 5) : null,
            'has_report' => (bool) $detail->report_path,
            'report_original_name' => $detail->report_original_name,
            'report_uploaded_at' => $detail->report_uploaded_at?->toIso8601String(),
            'report_uploaded_by' => $detail->reportUploader?->name,
        ];
    }
}
