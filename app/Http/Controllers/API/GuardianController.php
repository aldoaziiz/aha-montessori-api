<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\User;
use App\Services\Auth\CreateGuardianUserService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuardianController extends Controller
{
    private function forbidNonAdmin(): void
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Forbidden');
        }
    }

    public function index(Request $request)
    {
        $query = Guardian::with('status:id,name');

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('phone', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->sort_by && $request->sort_order) {
            $query->orderBy($request->sort_by, $request->sort_order);
        } else {
            $query->latest();
        }

        return $query->paginate($request->per_page ?? 10);
    }

    // ======================
    // CREATE
    // ======================

    public function store(Request $request)
    {
        $this->forbidNonAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email'],
            'id_number' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:255',
            'occupation' => 'nullable|string|max:255',
            'social_media' => 'nullable|string|max:255',
        ]);

        $emailExists = User::where('email', $validated['email'])->exists();

        if ($emailExists) {
            return response()->json([
                'message' => 'Email already registered',
            ], 422);
        }

        $validated['status_id'] = 1;

        $guardian = Guardian::create($validated);

        $user = (new CreateGuardianUserService)->execute(
            $guardian->name,
            $guardian->email,
            $guardian->phone
        );

        $guardian->update([
            'user_id' => $user->id,
        ]);

        return response()->json(
            $guardian->load([
                'status:id,name',
                'role:id,name',
            ]),
            201
        );
    }

    // ======================
    // SHOW
    // ======================

    public function show($id)
    {
        $guardian = Guardian::with([
            'status',
            'children:id,name',
        ])->find($id);

        if (! $guardian) {
            return response()->json([
                'message' => 'Not found',
            ], 404);
        }

        return response()->json($guardian);
    }

    // ======================
    // UPDATE
    // ======================

    public function update(Request $request, $id)
    {
        $this->forbidNonAdmin();

        $guardian = Guardian::with([
            'status',
            'role',
        ])->find($id);

        if (! $guardian) {
            return response()->json([
                'message' => 'Not found',
            ], 404);
        }

        $validated = $request->validate([
            'id_number' => 'nullable|string|max:255',
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:255',
            'occupation' => 'nullable|string|max:255',
            'social_media' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'status_id' => 'sometimes|integer|exists:statuses,id',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users', 'email')
                    ->ignore($guardian->user_id),
            ],

        ]);

        $guardian->update($validated);

        if ($guardian->user_id) {
            $user = User::find($guardian->user_id);

            if ($user) {
                $user->update([
                    'name' => $guardian->name,
                    'email' => $guardian->email,
                ]);
            }
        }

        return response()->json($guardian);
    }

    // ======================
    // DELETE
    // ======================

    public function destroy($id)
    {
        $this->forbidNonAdmin();

        $guardian = Guardian::with([
            'status',
            'role',
        ])->find($id);

        if (! $guardian) {
            return response()->json([
                'message' => 'Not found',
            ], 404);
        }

        $guardian->delete($id);

        return response()->json([
            'message' => 'Deleted',
        ]);
    }
}
