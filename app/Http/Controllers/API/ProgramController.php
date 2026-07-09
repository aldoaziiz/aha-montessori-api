<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    public function index(Request $request)
    {
        $query = Program::with([
            'payer:id,name',
            'clinic:id,name',
            'category:id,name',
            'status:id,name',
        ]);

        if ($request->clinic_id) {
            $query->where(
                'clinic_id',
                $request->clinic_id
            );
        }

        if ($request->program_category_id) {
            $query->where(
                'program_category_id',
                $request->program_category_id
            );
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('description', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->sort_by && $request->sort_order) {

            switch ($request->sort_by) {

                case 'clinic':

                    $query
                        ->leftJoin(
                            'clinics',
                            'programs.clinic_id',
                            '=',
                            'clinics.id'
                        )
                        ->select('programs.*')
                        ->orderBy(
                            'clinics.name',
                            $request->sort_order
                        );

                    break;

                case 'category':

                    $query
                        ->leftJoin(
                            'program_categories',
                            'programs.program_category_id',
                            '=',
                            'program_categories.id'
                        )
                        ->select('programs.*')
                        ->orderBy(
                            'program_categories.name',
                            $request->sort_order
                        );

                    break;

                case 'status':

                    $query
                        ->leftJoin(
                            'statuses',
                            'programs.status_id',
                            '=',
                            'statuses.id'
                        )
                        ->select('programs.*')
                        ->orderBy(
                            'statuses.name',
                            $request->sort_order
                        );

                    break;

                default:

                    $query->orderBy(
                        $request->sort_by,
                        $request->sort_order
                    );
            }

        } else {

            $query->orderBy('id', 'asc');

        }

        if ($request->per_page) {
            return ProgramResource::collection(
                $query->paginate($request->per_page)
            );
        }

        return ProgramResource::collection($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'payer_id' => 'required|exists:payers,id',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'session_count' => 'required|integer|min:0',
            'order_number' => 'nullable|integer',
            'clinic_id' => 'nullable|exists:clinics,id',
            'program_category_id' => 'nullable|exists:program_categories,id',
            'status_id' => 'nullable|exists:statuses,id',
        ]);

        $validated['status_id'] = $validated['status_id'] ?? 1;

        $program = Program::create($validated);

        return response()->json([
            'message' => 'Program created successfully',
            'data' => $program->load([
                'clinic:id,name',
                'category:id,name',
                'payer:id,name',
                'status:id,name',
            ]),
        ], 201);
    }

    public function show($id)
    {
        $program = Program::with([
            'clinic:id,name',
            'category:id,name',
            'payer:id,name',
            'status:id,name',
        ])->find($id);

        if (! $program) {
            return response()->json([
                'message' => 'Not found',
            ], 404);
        }

        return response()->json($program);
    }

    public function update(Request $request, $id)
    {
        $program = Program::find($id);

        if (! $program) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'payer_id' => 'required|exists:payers,id',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'order_number' => 'nullable|integer',
            'clinic_id' => 'nullable|exists:clinics,id',
            'program_category_id' => 'nullable|exists:program_categories,id',
            'status_id' => 'nullable|exists:statuses,id',
        ]);

        $program->update($validated);

        return response()->json([
            'message' => 'Program updated successfully',
            'data' => $program->load([
                'clinic:id,name',
                'category:id,name',
                'payer:id,name',
                'status:id,name',
            ]),
        ]);
    }

    public function destroy($id)
    {
        $program = Program::find($id);

        if (! $program) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $program->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function activate($id)
    {
        $program = Program::find($id);

        if (! $program) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $program->update(['status_id' => 1]);

        return response()->json([
            'message' => 'Program activated successfully',
            'data' => $program->load([
                'clinic:id,name',
                'category:id,name',
                'status:id,name',
            ]),
        ]);
    }

    public function deactivate($id)
    {
        $program = Program::find($id);

        if (! $program) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $program->update(['status_id' => 2]);

        return response()->json([
            'message' => 'Program deactivated successfully',
            'data' => $program->load([
                'clinic:id,name',
                'category:id,name',
                'status:id,name',
            ]),
        ]);
    }
}
