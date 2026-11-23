<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilestoneProgressReportController extends Controller
{
    private function authorizeReportUser(): void
    {
        if (! in_array(auth()->user()?->role, ['admin', 'teacher'], true)) {
            abort(403, 'Forbidden');
        }
    }

    public function children()
    {
        $this->authorizeReportUser();

        return response()->json([
            'data' => DB::table('children as c')
                ->join('statuses as s', 's.id', '=', 'c.status_id')
                ->whereRaw('LOWER(TRIM(s.name)) = ?', ['active'])
                ->orderBy('c.name')
                ->get(['c.id', 'c.name', 'c.id_number', 'c.birth_date']),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeReportUser();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('milestone_reports as mr')
            ->leftJoin('users as u', 'u.id', '=', 'mr.created_by')
            ->when(! empty($validated['start_date']), fn ($q) => $q->whereDate('mr.report_date', '>=', $validated['start_date']))
            ->when(! empty($validated['end_date']), fn ($q) => $q->whereDate('mr.report_date', '<=', $validated['end_date']))
            ->when(trim($validated['search'] ?? '') !== '', function ($q) use ($validated) {
                $term = '%'.trim($validated['search']).'%';
                $q->where(function ($subquery) use ($term) {
                    $subquery->where('mr.report_name', 'like', $term)
                        ->orWhere('mr.child_name_snapshot', 'like', $term)
                        ->orWhere('mr.curriculum_name_snapshot', 'like', $term);
                });
            })
            ->select([
                'mr.id', 'mr.report_name', 'mr.report_date', 'mr.child_name_snapshot',
                'mr.curriculum_name_snapshot', 'mr.created_at', 'u.name as created_by_name',
            ])
            ->orderByDesc('mr.report_date')->orderByDesc('mr.id');

        $reports = $query->paginate(min(max((int) ($validated['per_page'] ?? 10), 1), 100));

        return response()->json([
            'data' => $reports->items(), 'current_page' => $reports->currentPage(),
            'last_page' => $reports->lastPage(), 'per_page' => $reports->perPage(),
            'total' => $reports->total(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeReportUser();
        $validated = $request->validate([
            'child_id' => ['required', 'integer', 'exists:children,id'],
            'curriculum_id' => ['required', 'integer', 'exists:milestone_curricula,id'],
            'report_name' => ['required', 'string', 'max:255'],
            'report_date' => ['required', 'date_format:Y-m-d'],
            'academic_year_start_month' => ['required', 'integer', 'between:1,12'],
            'academic_year_start_year' => ['required', 'integer', 'between:2000,2100'],
            'academic_year_end_month' => ['required', 'integer', 'between:1,12'],
            'academic_year_end_year' => ['required', 'integer', 'between:2000,2100'],
            'teacher_comments' => ['nullable', 'string', 'max:20000'],
            'areas' => ['required', 'array', 'min:1'],
            'areas.*.area_id' => ['required', 'integer', 'exists:milestone_areas,id', 'distinct'],
            'areas.*.note' => ['nullable', 'string', 'max:20000'],
            'areas.*.milestones' => ['required', 'array', 'min:1'],
            'areas.*.milestones.*.milestone_id' => ['required', 'integer', 'exists:curriculum_milestones,id', 'distinct'],
            'areas.*.milestones.*.progress' => ['required', 'string', 'in:B,DB,M,K'],
        ]);

        $yearStart = ((int) $validated['academic_year_start_year'] * 100) + (int) $validated['academic_year_start_month'];
        $yearEnd = ((int) $validated['academic_year_end_year'] * 100) + (int) $validated['academic_year_end_month'];
        if ($yearEnd < $yearStart) {
            return response()->json(['message' => 'Academic year end must be the same as or later than its start.'], 422);
        }

        $child = DB::table('children as c')
            ->join('statuses as s', 's.id', '=', 'c.status_id')
            ->where('c.id', $validated['child_id'])
            ->whereRaw('LOWER(TRIM(s.name)) = ?', ['active'])
            ->first(['c.id', 'c.name', 'c.birth_date']);
        if (! $child) {
            return response()->json(['message' => 'Only active children can have a milestone report.'], 422);
        }

        $curriculum = DB::table('milestone_curricula')
            ->where('id', $validated['curriculum_id'])->where('is_active', 1)->first();
        if (! $curriculum) {
            return response()->json(['message' => 'The selected curriculum is not active.'], 422);
        }

        $reportDate = Carbon::parse($validated['report_date'])->startOfDay();
        $birthDate = $child->birth_date ? Carbon::parse($child->birth_date)->startOfDay() : null;
        if ($birthDate && $birthDate->greaterThan($reportDate)) {
            return response()->json(['message' => 'Report date cannot be earlier than the child’s birth date.'], 422);
        }
        $ageYears = $birthDate ? (int) $birthDate->diff($reportDate)->y : null;
        $ageMonths = $birthDate ? (int) $birthDate->diff($reportDate)->m : null;

        $validatedAreas = [];
        foreach ($validated['areas'] as $areaInput) {
            $area = DB::table('milestone_areas')->where('id', $areaInput['area_id'])
                ->where('curriculum_id', $curriculum->id)->where('is_active', 1)->first();
            if (! $area) {
                return response()->json(['message' => 'A selected area does not belong to the chosen active curriculum.'], 422);
            }

            $items = [];
            foreach ($areaInput['milestones'] as $milestoneInput) {
                $milestone = DB::table('curriculum_milestones as cm')
                    ->leftJoin('milestone_subareas as ms', 'ms.id', '=', 'cm.subarea_id')
                    ->where('cm.id', $milestoneInput['milestone_id'])
                    ->where('cm.area_id', $area->id)->where('cm.is_active', 1)
                    ->where(function ($query) {
                        $query->whereNull('cm.subarea_id')->orWhere('ms.is_active', 1);
                    })
                    ->first([
                        'cm.id', 'cm.name', 'cm.sort_order', 'cm.subarea_id',
                        'ms.name as subarea_name', 'ms.sort_order as subarea_sort_order',
                    ]);
                if (! $milestone) {
                    return response()->json(['message' => 'A selected milestone is unavailable in the chosen area.'], 422);
                }
                $items[] = [$milestone, $milestoneInput['progress']];
            }
            $validatedAreas[] = [$area, trim((string) ($areaInput['note'] ?? '')), $items];
        }

        $reportId = DB::transaction(function () use ($validated, $child, $curriculum, $ageYears, $ageMonths, $validatedAreas) {
            $reportId = DB::table('milestone_reports')->insertGetId([
                'child_id' => $child->id, 'curriculum_id' => $curriculum->id,
                'report_name' => trim($validated['report_name']), 'report_date' => $validated['report_date'],
                'academic_year_start_month' => $validated['academic_year_start_month'],
                'academic_year_start_year' => $validated['academic_year_start_year'],
                'academic_year_end_month' => $validated['academic_year_end_month'],
                'academic_year_end_year' => $validated['academic_year_end_year'],
                'teacher_comments' => trim((string) ($validated['teacher_comments'] ?? '')) ?: null,
                'child_name_snapshot' => $child->name, 'child_birth_date_snapshot' => $child->birth_date,
                'child_age_years_snapshot' => $ageYears, 'child_age_months_snapshot' => $ageMonths,
                'curriculum_name_snapshot' => $curriculum->name, 'created_by' => auth()->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($validatedAreas as [$area, $note, $items]) {
                $reportAreaId = DB::table('milestone_report_areas')->insertGetId([
                    'report_id' => $reportId, 'master_area_id' => $area->id,
                    'area_name_snapshot' => $area->name, 'display_order' => $area->sort_order,
                    'area_note' => $note ?: null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($items as [$milestone, $progress]) {
                    DB::table('milestone_report_items')->insert([
                        'report_area_id' => $reportAreaId, 'master_milestone_id' => $milestone->id,
                        'master_subarea_id' => $milestone->subarea_id,
                        'subarea_name_snapshot' => $milestone->subarea_name,
                        'subarea_order_snapshot' => $milestone->subarea_sort_order,
                        'milestone_name_snapshot' => $milestone->name,
                        'display_order' => $milestone->sort_order, 'progress_code' => $progress,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            DB::table('milestone_audit_logs')->insert([
                'actor_user_id' => auth()->id(), 'action' => 'report.created',
                'target_type' => 'report', 'target_id' => $reportId,
                'target_label' => mb_substr(trim($validated['report_name']), 0, 255),
                'event_context' => json_encode([
                    'child_id' => $child->id, 'child_name' => $child->name,
                    'curriculum' => $curriculum->name, 'report_date' => $validated['report_date'],
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);

            return $reportId;
        });

        return response()->json(['data' => $this->reportData($reportId), 'message' => 'Milestone report created.'], 201);
    }

    public function show(int $report)
    {
        $this->authorizeReportUser();
        abort_unless(DB::table('milestone_reports')->where('id', $report)->exists(), 404);

        return response()->json(['data' => $this->reportData($report)]);
    }

    private function reportData(int $reportId): array
    {
        $report = DB::table('milestone_reports as mr')
            ->leftJoin('users as u', 'u.id', '=', 'mr.created_by')
            ->where('mr.id', $reportId)
            ->first(['mr.*', 'u.name as created_by_name']);
        $areas = DB::table('milestone_report_areas')->where('report_id', $reportId)
            ->orderBy('display_order')->orderBy('id')->get();
        $items = DB::table('milestone_report_items')->whereIn('report_area_id', $areas->pluck('id')->all() ?: [0])
            ->orderBy('subarea_order_snapshot')->orderBy('display_order')->orderBy('id')->get()->groupBy('report_area_id');

        return array_merge((array) $report, [
            'academic_year' => sprintf('%02d/%d - %02d/%d', $report->academic_year_start_month, $report->academic_year_start_year, $report->academic_year_end_month, $report->academic_year_end_year),
            'age_at_report' => $report->child_age_years_snapshot === null ? null : sprintf('%d tahun %d bulan', $report->child_age_years_snapshot, $report->child_age_months_snapshot),
            'areas' => $areas->map(fn ($area) => array_merge((array) $area, ['items' => ($items->get($area->id) ?? collect())->values()]))->values()->all(),
        ]);
    }

    public function destroy(int $report)
    {
        $this->authorizeReportUser();
        $result = DB::transaction(function () use ($report) {
            $row = DB::table('milestone_reports')->where('id', $report)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('milestone_audit_logs')->insert([
                'actor_user_id' => auth()->id(), 'action' => 'report.deleted',
                'target_type' => 'report', 'target_id' => $row->id,
                'target_label' => mb_substr($row->report_name, 0, 255),
                'event_context' => json_encode([
                    'child_id' => $row->child_id, 'child_name' => $row->child_name_snapshot,
                    'curriculum' => $row->curriculum_name_snapshot, 'report_date' => $row->report_date,
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);
            DB::table('milestone_reports')->where('id', $report)->delete();

            return true;
        });

        return response()->json(['message' => $result ? 'Milestone report permanently deleted.' : 'Unable to delete report.']);
    }

    public function downloadPdf(int $report)
    {
        $this->authorizeReportUser();
        abort_unless(DB::table('milestone_reports')->where('id', $report)->exists(), 404);
        $data = $this->reportData($report);
        $pdf = Pdf::loadView('pdf.milestone-progress-report', ['report' => $data])->setPaper('a4');
        $filename = str_replace(['/', '\\'], '-', $data['report_name']).'.pdf';

        return $pdf->download($filename);
    }
}
