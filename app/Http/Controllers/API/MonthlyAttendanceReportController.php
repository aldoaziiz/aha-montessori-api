<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TherapySession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonthlyAttendanceReportController extends Controller
{
    public function index(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Forbidden');
        }

        $validated = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $categoryFallback = DB::table('registration_programs as rp')
            ->join('programs as p', 'p.id', '=', 'rp.program_id')
            ->join('program_categories as pc', 'pc.id', '=', 'p.program_category_id')
            ->select('rp.registration_id')
            ->selectRaw('COUNT(DISTINCT pc.id) as category_count')
            ->selectRaw('MIN(pc.name) as category_name')
            ->groupBy('rp.registration_id');

        $categoryExpression = "COALESCE(direct_category.name, CASE WHEN fallback_category.category_count = 1 THEN fallback_category.category_name ELSE 'Category unavailable' END)";

        $query = DB::table('therapy_sessions as ts')
            ->join('registrations as r', 'r.id', '=', 'ts.registration_id')
            ->join('children as c', 'c.id', '=', 'r.child_id')
            ->leftJoin('cities as birthplace', 'birthplace.id', '=', 'c.birthplace_id')
            ->leftJoin('schools as school', 'school.id', '=', 'c.school_id')
            ->leftJoin('program_categories as direct_category', 'direct_category.id', '=', 'r.program_category_id')
            ->leftJoinSub($categoryFallback, 'fallback_category', function ($join) {
                $join->on('fallback_category.registration_id', '=', 'r.id');
            })
            ->leftJoin('child_guardians as cg', 'cg.child_id', '=', 'c.id')
            ->leftJoin('guardians as guardian', 'guardian.id', '=', 'cg.guardian_id')
            ->where('ts.therapy_session_status_id', TherapySession::STATUS_COMPLETED)
            ->whereBetween('ts.therapy_date', [$validated['start_date'], $validated['end_date']])
            ->when(isset($validated['category']) && $validated['category'] !== '', function ($query) use ($categoryExpression, $validated) {
                $query->whereRaw($categoryExpression.' = ?', [$validated['category']]);
            })
            ->selectRaw("DATE_FORMAT(ts.therapy_date, '%Y-%m') as report_month")
            ->addSelect([
                'c.id as child_id',
                'c.name as child_name',
                'c.birth_date',
                'birthplace.name as birthplace',
                'c.address',
                'c.id_number',
                'school.name as school_name',
                'c.special_condition',
            ])
            ->selectRaw($categoryExpression.' as program_category')
            ->selectRaw("GROUP_CONCAT(DISTINCT guardian.name ORDER BY guardian.name SEPARATOR ', ') as guardian_names")
            ->selectRaw('COUNT(DISTINCT ts.id) as completed_sessions')
            ->groupByRaw("DATE_FORMAT(ts.therapy_date, '%Y-%m'), c.id, c.name, c.birth_date, birthplace.name, c.address, c.id_number, school.name, c.special_condition, ".$categoryExpression)
            ->orderBy('report_month')
            ->orderBy('c.name')
            ->orderBy('program_category');

        return response()->json([
            'data' => $query->get(),
        ]);
    }
}
