<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MilestoneCurriculumController extends Controller
{
    private function authorizeManager(bool $adminOnly = false): void
    {
        $role = auth()->user()?->role;
        $allowed = $adminOnly ? ['admin'] : ['admin', 'teacher'];

        if (! in_array($role, $allowed, true)) {
            abort(403, 'Forbidden');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeManager();
        $includeArchived = auth()->user()->role === 'admin'
            && $request->boolean('include_archived');

        $curricula = DB::table('milestone_curricula')
            ->when(! $includeArchived, fn ($query) => $query->where('is_active', 1))
            ->orderBy('name')
            ->get();

        $curriculumIds = $curricula->pluck('id')->all();
        $areas = DB::table('milestone_areas')
            ->whereIn('curriculum_id', $curriculumIds ?: [0])
            ->when(! $includeArchived, fn ($query) => $query->where('is_active', 1))
            ->orderBy('sort_order')->orderBy('name')->get();

        $areaIds = $areas->pluck('id')->all();
        $subareas = DB::table('milestone_subareas')
            ->whereIn('area_id', $areaIds ?: [0])
            ->when(! $includeArchived, fn ($query) => $query->where('is_active', 1))
            ->orderBy('sort_order')->orderBy('name')->get();
        $milestones = DB::table('curriculum_milestones')
            ->whereIn('area_id', $areaIds ?: [0])
            ->when(! $includeArchived, fn ($query) => $query->where('is_active', 1))
            ->orderBy('sort_order')->orderBy('name')->get();

        $milestoneIds = $milestones->pluck('id')->all();
        $ages = DB::table('curriculum_milestone_ages')
            ->whereIn('milestone_id', $milestoneIds ?: [0])
            ->orderBy('age')->get()->groupBy('milestone_id');

        $data = $curricula->map(function ($curriculum) use ($areas, $subareas, $milestones, $ages) {
            $curriculumAreas = $areas->where('curriculum_id', $curriculum->id)->map(function ($area) use ($subareas, $milestones, $ages) {
                $areaSubareas = $subareas->where('area_id', $area->id)->map(function ($subarea) use ($milestones, $ages) {
                    return array_merge((array) $subarea, [
                        'milestones' => $milestones->where('subarea_id', $subarea->id)->map(fn ($milestone) => $this->milestonePayload($milestone, $ages))->values(),
                    ]);
                })->values();

                return array_merge((array) $area, [
                    'subareas' => $areaSubareas,
                    'milestones' => $milestones->where('area_id', $area->id)->whereNull('subarea_id')
                        ->map(fn ($milestone) => $this->milestonePayload($milestone, $ages))->values(),
                ]);
            })->values();

            return array_merge((array) $curriculum, ['areas' => $curriculumAreas]);
        })->values();

        return response()->json(['data' => $data]);
    }

    private function milestonePayload(object $milestone, $ages): array
    {
        return array_merge((array) $milestone, [
            'ages' => $ages->get($milestone->id, collect())->pluck('age')->map(fn ($age) => (int) $age)->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeManager(true);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160', 'unique:milestone_curricula,name'],
        ]);

        $id = DB::transaction(function () use ($validated) {
            $id = DB::table('milestone_curricula')->insertGetId([
                'name' => trim($validated['name']), 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('curriculum.created', 'curriculum', $id, $validated['name']);

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Curriculum created.'], 201);
    }

    public function update(Request $request, int $curriculum)
    {
        $this->authorizeManager(true);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('milestone_curricula', 'name')->ignore($curriculum)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($curriculum, $validated) {
            $row = DB::table('milestone_curricula')->where('id', $curriculum)->lockForUpdate()->first();
            abort_if(! $row, 404);
            DB::table('milestone_curricula')->where('id', $curriculum)->update([
                'name' => trim($validated['name']),
                'is_active' => $validated['is_active'] ?? $row->is_active,
                'updated_at' => now(),
            ]);
            $this->audit('curriculum.updated', 'curriculum', $curriculum, $validated['name'], [
                'previous_name' => $row->name,
                'previously_active' => (bool) $row->is_active,
                'active' => (bool) ($validated['is_active'] ?? $row->is_active),
            ]);
        });

        return response()->json(['message' => 'Curriculum updated.']);
    }

    public function destroy(int $curriculum)
    {
        $this->authorizeManager(true);
        [$used, $message] = DB::transaction(function () use ($curriculum) {
            $row = DB::table('milestone_curricula')->where('id', $curriculum)->lockForUpdate()->first();
            abort_if(! $row, 404);
            $used = DB::table('milestone_reports')->where('curriculum_id', $curriculum)->exists();

            if ($used) {
                DB::table('milestone_curricula')->where('id', $curriculum)->update(['is_active' => 0, 'updated_at' => now()]);
                $message = 'Curriculum is used by a report and was archived.';
                $action = 'curriculum.archived';
            } else {
                DB::table('milestone_curricula')->where('id', $curriculum)->delete();
                $message = 'Curriculum deleted.';
                $action = 'curriculum.deleted';
            }

            $this->audit($action, 'curriculum', $curriculum, $row->name);

            return [$used, $message];
        });

        return response()->json(['message' => $message, 'archived' => $used]);
    }

    public function storeArea(Request $request)
    {
        $this->authorizeManager(true);
        $validated = $request->validate([
            'curriculum_id' => ['required', 'integer', 'exists:milestone_curricula,id'],
            'name' => ['required', 'string', 'max:160'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        abort_if(DB::table('milestone_areas')->where('curriculum_id', $validated['curriculum_id'])->where('name', trim($validated['name']))->exists(), 422, 'An area with this name already exists in the curriculum.');
        $id = DB::transaction(function () use ($validated) {
            $id = DB::table('milestone_areas')->insertGetId([
                'curriculum_id' => $validated['curriculum_id'], 'name' => trim($validated['name']),
                'sort_order' => $validated['sort_order'] ?? 0, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('area.created', 'area', $id, $validated['name'], ['curriculum_id' => $validated['curriculum_id']]);

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Area created.'], 201);
    }

    public function updateArea(Request $request, int $area)
    {
        $this->authorizeManager(true);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($area, $validated) {
            $row = DB::table('milestone_areas')->where('id', $area)->lockForUpdate()->first();
            abort_if(! $row, 404);
            abort_if(DB::table('milestone_areas')->where('curriculum_id', $row->curriculum_id)->where('name', trim($validated['name']))->where('id', '<>', $area)->exists(), 422, 'An area with this name already exists in the curriculum.');
            DB::table('milestone_areas')->where('id', $area)->update([
                'name' => trim($validated['name']), 'sort_order' => $validated['sort_order'] ?? $row->sort_order,
                'is_active' => $validated['is_active'] ?? $row->is_active, 'updated_at' => now(),
            ]);
            $this->audit('area.updated', 'area', $area, $validated['name'], ['curriculum_id' => $row->curriculum_id, 'previous_name' => $row->name]);
        });

        return response()->json(['message' => 'Area updated.']);
    }

    public function destroyArea(int $area)
    {
        $this->authorizeManager(true);
        $used = DB::transaction(function () use ($area) {
            $row = DB::table('milestone_areas')->where('id', $area)->lockForUpdate()->first();
            abort_if(! $row, 404);
            $used = DB::table('milestone_report_areas')->where('master_area_id', $area)->exists()
                || DB::table('milestone_report_items as ri')->join('curriculum_milestones as cm', 'cm.id', '=', 'ri.master_milestone_id')->where('cm.area_id', $area)->exists();
            if ($used) {
                DB::table('milestone_areas')->where('id', $area)->update(['is_active' => 0, 'updated_at' => now()]);
            } else {
                DB::table('milestone_areas')->where('id', $area)->delete();
            }
            $this->audit($used ? 'area.archived' : 'area.deleted', 'area', $area, $row->name, ['curriculum_id' => $row->curriculum_id]);

            return $used;
        });

        return response()->json(['message' => $used ? 'Area is used by a report and was archived.' : 'Area deleted.', 'archived' => $used]);
    }

    public function storeSubarea(Request $request)
    {
        $this->authorizeManager(true);
        $validated = $request->validate([
            'area_id' => ['required', 'integer', 'exists:milestone_areas,id'], 'name' => ['required', 'string', 'max:160'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        abort_if(DB::table('milestone_subareas')->where('area_id', $validated['area_id'])->where('name', trim($validated['name']))->exists(), 422, 'A subarea with this name already exists in the area.');
        $id = DB::transaction(function () use ($validated) {
            $id = DB::table('milestone_subareas')->insertGetId([
                'area_id' => $validated['area_id'], 'name' => trim($validated['name']), 'sort_order' => $validated['sort_order'] ?? 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('subarea.created', 'subarea', $id, $validated['name'], ['area_id' => $validated['area_id']]);

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Subarea created.'], 201);
    }

    public function updateSubarea(Request $request, int $subarea)
    {
        $this->authorizeManager(true);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($subarea, $validated) {
            $row = DB::table('milestone_subareas')->where('id', $subarea)->lockForUpdate()->first();
            abort_if(! $row, 404);
            abort_if(DB::table('milestone_subareas')->where('area_id', $row->area_id)->where('name', trim($validated['name']))->where('id', '<>', $subarea)->exists(), 422, 'A subarea with this name already exists in the area.');
            DB::table('milestone_subareas')->where('id', $subarea)->update([
                'name' => trim($validated['name']), 'sort_order' => $validated['sort_order'] ?? $row->sort_order,
                'is_active' => $validated['is_active'] ?? $row->is_active, 'updated_at' => now(),
            ]);
            $this->audit('subarea.updated', 'subarea', $subarea, $validated['name'], ['area_id' => $row->area_id, 'previous_name' => $row->name]);
        });

        return response()->json(['message' => 'Subarea updated.']);
    }

    public function destroySubarea(int $subarea)
    {
        $this->authorizeManager(true);
        $used = DB::transaction(function () use ($subarea) {
            $row = DB::table('milestone_subareas')->where('id', $subarea)->lockForUpdate()->first();
            abort_if(! $row, 404);
            $used = DB::table('milestone_report_items')->where('master_subarea_id', $subarea)->exists()
                || DB::table('curriculum_milestones as cm')->join('milestone_report_items as ri', 'ri.master_milestone_id', '=', 'cm.id')->where('cm.subarea_id', $subarea)->exists();
            if ($used) {
                DB::table('milestone_subareas')->where('id', $subarea)->update(['is_active' => 0, 'updated_at' => now()]);
            } else {
                DB::table('milestone_subareas')->where('id', $subarea)->delete();
            }
            $this->audit($used ? 'subarea.archived' : 'subarea.deleted', 'subarea', $subarea, $row->name, ['area_id' => $row->area_id]);

            return $used;
        });

        return response()->json(['message' => $used ? 'Subarea is used by a report and was archived.' : 'Subarea deleted.', 'archived' => $used]);
    }

    public function storeMilestone(Request $request)
    {
        $this->authorizeManager(true);
        $validated = $this->validateMilestone($request);
        abort_if(DB::table('curriculum_milestones')->where('area_id', $validated['area_id'])->where('name', trim($validated['name']))->exists(), 422, 'A milestone with this name already exists in the area.');
        $id = DB::transaction(function () use ($validated) {
            $id = DB::table('curriculum_milestones')->insertGetId([
                'area_id' => $validated['area_id'], 'subarea_id' => $validated['subarea_id'] ?? null,
                'name' => trim($validated['name']), 'sort_order' => $validated['sort_order'] ?? 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->saveAges($id, $validated['ages'] ?? []);
            $this->audit('milestone.created', 'milestone', $id, $validated['name'], ['area_id' => $validated['area_id'], 'subarea_id' => $validated['subarea_id'] ?? null]);

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Milestone created.'], 201);
    }

    public function updateMilestone(Request $request, int $milestone)
    {
        $this->authorizeManager(true);
        $validated = $this->validateMilestone($request);
        DB::transaction(function () use ($milestone, $validated) {
            $row = DB::table('curriculum_milestones')->where('id', $milestone)->lockForUpdate()->first();
            abort_if(! $row, 404);
            abort_if(DB::table('curriculum_milestones')->where('area_id', $validated['area_id'])->where('name', trim($validated['name']))->where('id', '<>', $milestone)->exists(), 422, 'A milestone with this name already exists in the area.');
            DB::table('curriculum_milestones')->where('id', $milestone)->update([
                'area_id' => $validated['area_id'], 'subarea_id' => $validated['subarea_id'] ?? null,
                'name' => trim($validated['name']), 'sort_order' => $validated['sort_order'] ?? $row->sort_order,
                'is_active' => $validated['is_active'] ?? $row->is_active, 'updated_at' => now(),
            ]);
            $this->saveAges($milestone, $validated['ages'] ?? []);
            $this->audit('milestone.updated', 'milestone', $milestone, $validated['name'], ['area_id' => $validated['area_id'], 'subarea_id' => $validated['subarea_id'] ?? null, 'previous_name' => $row->name]);
        });

        return response()->json(['message' => 'Milestone updated.']);
    }

    private function validateMilestone(Request $request): array
    {
        $validated = $request->validate([
            'area_id' => ['required', 'integer', 'exists:milestone_areas,id'],
            'subarea_id' => ['nullable', 'integer', 'exists:milestone_subareas,id'],
            'name' => ['required', 'string', 'max:255'], 'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'], 'ages' => ['nullable', 'array'], 'ages.*' => ['integer', 'min:1', 'max:99'],
        ]);
        if (! empty($validated['subarea_id']) && ! DB::table('milestone_subareas')
            ->where('id', $validated['subarea_id'])->where('area_id', $validated['area_id'])->exists()) {
            abort(422, 'The selected subarea must belong to the selected area.');
        }

        return $validated;
    }

    private function saveAges(int $milestone, array $ages): void
    {
        DB::table('curriculum_milestone_ages')->where('milestone_id', $milestone)->delete();
        foreach (array_unique($ages) as $age) {
            DB::table('curriculum_milestone_ages')->insert(['milestone_id' => $milestone, 'age' => $age]);
        }
    }

    public function destroyMilestone(int $milestone)
    {
        $this->authorizeManager(true);
        $used = DB::transaction(function () use ($milestone) {
            $row = DB::table('curriculum_milestones')->where('id', $milestone)->lockForUpdate()->first();
            abort_if(! $row, 404);
            $used = DB::table('milestone_report_items')->where('master_milestone_id', $milestone)->exists();
            if ($used) {
                DB::table('curriculum_milestones')->where('id', $milestone)->update(['is_active' => 0, 'updated_at' => now()]);
            } else {
                DB::table('curriculum_milestones')->where('id', $milestone)->delete();
            }
            $this->audit($used ? 'milestone.archived' : 'milestone.deleted', 'milestone', $milestone, $row->name, ['area_id' => $row->area_id]);

            return $used;
        });

        return response()->json(['message' => $used ? 'Milestone is used by a report and was archived.' : 'Milestone deleted.', 'archived' => $used]);
    }

    public function auditLogs(Request $request)
    {
        $this->authorizeManager(true);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = DB::table('milestone_audit_logs as log')
            ->leftJoin('users as actor', 'actor.id', '=', 'log.actor_user_id')
            ->when(trim($validated['search'] ?? '') !== '', function ($query) use ($validated) {
                $term = '%'.trim($validated['search']).'%';
                $query->where(function ($subquery) use ($term) {
                    $subquery->where('log.target_label', 'like', $term)
                        ->orWhere('log.action', 'like', $term)
                        ->orWhere('actor.name', 'like', $term)
                        ->orWhere('actor.email', 'like', $term);
                });
            })
            ->orderByDesc('log.created_at')->orderByDesc('log.id')
            ->select(['log.id', 'actor.name as actor_name', 'actor.email as actor_email', 'log.action', 'log.target_type', 'log.target_id', 'log.target_label', 'log.event_context', 'log.ip_address', 'log.created_at'])
            ->paginate(min(max((int) ($validated['per_page'] ?? 25), 1), 100));

        return response()->json([
            'data' => $logs->items(), 'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(), 'per_page' => $logs->perPage(), 'total' => $logs->total(),
        ]);
    }

    private function audit(string $action, string $type, ?int $id, string $label, array $context = []): void
    {
        DB::table('milestone_audit_logs')->insert([
            'actor_user_id' => auth()->id(), 'action' => $action, 'target_type' => $type,
            'target_id' => $id, 'target_label' => mb_substr($label, 0, 255),
            'event_context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }
}
