<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Child;
use Illuminate\Http\Request;

class ChildController extends Controller
{
    private function forbidNonAdmin()
    {
        if (
            auth()->user()->role !==
            'admin'
        ) {

            abort(
                403,
                'Forbidden'
            );

        }
    }

    public function index(Request $request)
    {
        $query = Child::with([
            'status:id,name',
        ]);

        // ======================
        // SEARCH
        // ======================

        if ($request->search) {

            $query->where(
                'name',
                'like',
                '%'.$request->search.'%'
            );
        }

        // ======================
        // SORTING
        // ======================

        if (
            $request->sort_by &&
            $request->sort_order
        ) {

            $query->orderBy(
                $request->sort_by,
                $request->sort_order
            );
        } else {

            $query->latest();
        }

        // ======================
        // PAGINATION
        // ======================

        return $query->paginate(
            $request->per_page ?? 10
        );
    }

    // POST create
    public function store(Request $request)
    {
        $this->forbidNonAdmin();

        $child = Child::create($request->all());

        return response()->json($child, 201);
    }

    // GET by id
    public function show($id)
    {
        $child = Child::query()
            ->with([
                'birthplace:id,name',
                'hometown:id,name',
                'school:id,name',
                'schoolClass:id,name',
                'schoolEducation:id,name',
                'guardians:id,name,phone',
            ])->find($id);

        if (! $child) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($child);
    }

    // PUT update
    public function update(Request $request, $id)
    {
        $this->forbidNonAdmin();

        $child = Child::query()->find($id);

        if (! $child) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $child->update($request->all());

        return response()->json($child);
    }

    // DELETE
    public function destroy($id)
    {
        $this->forbidNonAdmin();

        $child = Child::query()->find($id);

        if (! $child) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $child->delete($id);

        return response()->json(['message' => 'Deleted']);
    }
}
