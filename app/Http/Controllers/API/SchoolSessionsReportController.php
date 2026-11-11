<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TherapySession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchoolSessionsReportController extends Controller
{
    public function index(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Forbidden');
        }

        $validated = $request->validate([
            'program_category_id' => ['nullable', 'integer', 'exists:program_categories,id'],
            'child_name' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort_by' => ['nullable', 'in:id_number,child_name,program_category,session_started_at,session_expired_at,total_sessions,used_sessions,remaining_sessions'],
            'sort_order' => ['nullable', 'in:asc,desc'],
        ]);

        // Older registrations may not have a direct category. Infer one only
        // when all programs on that registration belong to the same category.
        $categoryFallback = DB::table('registration_programs as rp')
            ->join('programs as p', 'p.id', '=', 'rp.program_id')
            ->select('rp.registration_id')
            ->selectRaw('COUNT(DISTINCT p.program_category_id) as category_count')
            ->selectRaw('MIN(p.program_category_id) as category_id')
            ->groupBy('rp.registration_id');

        $registrationCategories = DB::table('registrations as r')
            ->leftJoinSub($categoryFallback, 'category_fallback', function ($join) {
                $join->on('category_fallback.registration_id', '=', 'r.id');
            })
            ->select([
                'r.id as registration_id',
                'r.child_id',
                'r.total_session',
            ])
            ->selectRaw('COALESCE(r.program_category_id, CASE WHEN category_fallback.category_count = 1 THEN category_fallback.category_id ELSE NULL END) as program_category_id');

        $usedSessions = DB::table('therapy_sessions as ts')
            ->select('ts.registration_id')
            ->selectRaw('COUNT(*) as used_sessions')
            ->where('ts.therapy_session_status_id', TherapySession::STATUS_COMPLETED)
            ->groupBy('ts.registration_id');

        $summary = DB::table('children as c')
            ->joinSub($registrationCategories, 'registration_categories', function ($join) {
                $join->on('registration_categories.child_id', '=', 'c.id');
            })
            ->leftJoin('program_categories as pc', 'pc.id', '=', 'registration_categories.program_category_id')
            ->leftJoinSub($usedSessions, 'used_sessions', function ($join) {
                $join->on('used_sessions.registration_id', '=', 'registration_categories.registration_id');
            })
            ->selectRaw("CONCAT(c.id, ':', COALESCE(registration_categories.program_category_id, 'unknown')) as id")
            ->addSelect([
                'c.id as child_id',
                'c.id_number',
                'c.name as child_name',
                'registration_categories.program_category_id',
                'pc.name as program_category_name',
                'c.session_started_at',
                'c.session_expired_at',
            ])
            ->selectRaw('SUM(COALESCE(registration_categories.total_session, 0)) as total_sessions')
            ->selectRaw('SUM(COALESCE(used_sessions.used_sessions, 0)) as used_sessions')
            ->when(! empty($validated['program_category_id']), function ($query) use ($validated) {
                $query->where('registration_categories.program_category_id', $validated['program_category_id']);
            })
            ->when(trim($validated['child_name'] ?? '') !== '', function ($query) use ($validated) {
                $query->where('c.name', 'like', '%'.trim($validated['child_name']).'%');
            })
            ->groupBy([
                'c.id',
                'c.id_number',
                'c.name',
                'registration_categories.program_category_id',
                'pc.name',
                'c.session_started_at',
                'c.session_expired_at',
            ]);

        $sortColumns = [
            'id_number' => 'id_number',
            'child_name' => 'child_name',
            'program_category' => 'program_category_name',
            'session_started_at' => 'session_started_at',
            'session_expired_at' => 'session_expired_at',
            'total_sessions' => 'total_sessions',
            'used_sessions' => 'used_sessions',
            'remaining_sessions' => 'remaining_sessions',
        ];
        $sortBy = $validated['sort_by'] ?? 'child_name';
        $sortOrder = $validated['sort_order'] ?? 'asc';

        $query = DB::query()->fromSub($summary, 'session_summary');

        if ($sortBy === 'remaining_sessions') {
            $query->orderByRaw('(COALESCE(total_sessions, 0) - COALESCE(used_sessions, 0)) '.$sortOrder);
        } else {
            $query->orderBy($sortColumns[$sortBy], $sortOrder);
        }

        $paginator = $query
            ->orderBy('child_id')
            ->orderBy('program_category_id')
            ->paginate(min(max((int) ($validated['per_page'] ?? 10), 1), 50));

        $data = collect($paginator->items())->map(function ($row) {
            $total = (int) $row->total_sessions;
            $used = (int) $row->used_sessions;

            return [
                'id' => (string) $row->id,
                'id_number' => $row->id_number,
                'child_name' => $row->child_name,
                'program_category' => $row->program_category_name ?? '-',
                'session_started_at' => $row->session_started_at,
                'session_expired_at' => $row->session_expired_at,
                'total_sessions' => $total,
                'used_sessions' => $used,
                'remaining_sessions' => $total - $used,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }
}
