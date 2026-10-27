<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Child;
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
            'sort_by' => ['nullable', 'in:id_number,child_name,program_category,total_sessions,used_sessions,remaining_sessions'],
            'sort_order' => ['nullable', 'in:asc,desc'],
        ]);

        $totalSessions = DB::table('registrations')
            ->selectRaw('COALESCE(SUM(total_session), 0)')
            ->whereColumn('registrations.child_id', 'children.id');

        $usedSessions = DB::table('therapy_sessions')
            ->join('registrations', 'registrations.id', '=', 'therapy_sessions.registration_id')
            ->selectRaw('COUNT(*)')
            ->whereColumn('registrations.child_id', 'children.id')
            ->where('therapy_sessions.therapy_session_status_id', TherapySession::STATUS_COMPLETED);

        $query = Child::query()
            ->select([
                'children.id',
                'children.id_number',
                'children.name as child_name',
                'children.program_category_id',
            ])
            ->selectSub($totalSessions, 'total_sessions')
            ->selectSub($usedSessions, 'used_sessions')
            ->with('programCategory:id,name')
            ->whereHas('registrations');

        if (! empty($validated['program_category_id'])) {
            $query->where('children.program_category_id', $validated['program_category_id']);
        }

        $childName = trim($validated['child_name'] ?? '');
        if ($childName !== '') {
            $query->where('children.name', 'like', '%'.$childName.'%');
        }

        $sortColumns = [
            'id_number' => 'children.id_number',
            'child_name' => 'children.name',
            'program_category' => 'program_categories.name',
            'total_sessions' => 'total_sessions',
            'used_sessions' => 'used_sessions',
            'remaining_sessions' => 'remaining_sessions',
        ];
        $sortBy = $validated['sort_by'] ?? 'child_name';
        $sortOrder = $validated['sort_order'] ?? 'asc';

        if ($sortBy === 'program_category') {
            $query->leftJoin('program_categories', 'program_categories.id', '=', 'children.program_category_id')
                ->addSelect('program_categories.name as program_category_name');
        }

        if ($sortBy === 'remaining_sessions') {
            $query->orderByRaw('(COALESCE(total_sessions, 0) - COALESCE(used_sessions, 0)) '.$sortOrder);
        } else {
            $query->orderBy($sortColumns[$sortBy], $sortOrder);
        }

        $paginator = $query
            ->orderBy('children.id')
            ->paginate(min(max((int) ($validated['per_page'] ?? 10), 1), 50));

        $data = $paginator->getCollection()->map(function (Child $child) {
            $total = (int) $child->total_sessions;
            $used = (int) $child->used_sessions;

            return [
                'id' => $child->id,
                'id_number' => $child->id_number,
                'child_name' => $child->child_name,
                'program_category' => $child->programCategory?->name ?? '-',
                'total_sessions' => $total,
                'used_sessions' => $used,
                'remaining_sessions' => $total - $used,
            ];
        });

        return response()->json([
            'data' => $data,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }
}
