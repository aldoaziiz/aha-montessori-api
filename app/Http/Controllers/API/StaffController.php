<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\Auth\CreateStaffUserService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $query = Staff::with([
            'status:id,name',
            'staffRole:id,name',
        ]);

        // 🔥 FILTER ROLE
        if ($request->staff_role_id) {
            $query->where(
                'staff_role_id',
                $request->staff_role_id
            );
        }

        // SEARCH
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%')
                    ->orWhere('phone', 'like', '%'.$request->search.'%');
            });
        }

        // SORTING
        if ($request->sort_by && $request->sort_order) {
            $query->orderBy($request->sort_by, $request->sort_order);
        } else {
            $query->latest();
        }

        return $query->paginate($request->per_page ?? 10);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'name' => 'required|string|max:255',

            'email' => 'required|email|max:255',

            'phone' => 'required|string|max:255',

            'address' => 'nullable|string',

            'staff_role_id' => 'required|exists:staff_roles,id',

        ]);

        // ======================
        // CHECK EMAIL
        // ======================

        $emailExists =
            User::where(
                'email',
                $validated['email']
            )->exists();

        if ($emailExists) {

            return response()->json([

                'message' => 'Email already registered',

            ], 422);

        }

        // ======================
        // DEFAULT STATUS
        // ======================

        $validated['status_id'] = 1;

        // ======================
        // DETERMINE ROLE
        // ======================

        $role = 'staff';

        if ($validated['staff_role_id']) {

            $staffRole =
                StaffRole::find(
                    $validated['staff_role_id']
                );

            $staffRoleName =
                strtolower(
                    $staffRole?->name ?? ''
                );

            if (

                str_contains(
                    $staffRoleName,
                    'therapist'
                )

            ) {

                $role = 'therapist';

            }

            if (

                str_contains(
                    $staffRoleName,
                    'admin'
                )

            ) {

                $role = 'admin';

            }
        }

        // ======================
        // CREATE STAFF
        // ======================

        $staff = Staff::create(
            $validated
        );

        // ======================
        // CREATE USER
        // ======================

        $userService =
            new CreateStaffUserService;

        $user =
            $userService->execute(
                $staff->name,
                $staff->email,
                $staff->phone,
                $role
            );

        // ======================
        // CONNECT USER
        // ======================

        $staff->update([

            'user_id' => $user->id,

        ]);

        return response()->json([

            'message' => 'Staff created successfully',

            'data' => $staff->load([

                'staffRole:id,name',

                'status:id,name',

            ]),

        ], 201);
    }

    public function show($id)
    {
        $staff = Staff::query()->with([
            'staffRole:id,name',
        ])->find($id);

        if (! $staff) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($staff);
    }

    public function update(
        Request $request,
        $id
    ) {
        $staff = Staff::query()
            ->find($id);

        if (! $staff) {

            return response()->json([
                'message' => 'Not found',
            ], 404);
        }

        $validated = $request->validate([

            'name' => 'required|string|max:255',

            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')
                    ->ignore($staff->user_id),
            ],

            'phone' => 'required|string|max:255',

            'address' => 'nullable|string',

            'staff_role_id' => 'required|exists:staff_roles,id',

            'status_id' => 'nullable|exists:statuses,id',

        ]);

        $staff->update($validated);

        $role = 'staff';

        $staffRole =
            StaffRole::find(
                $validated['staff_role_id']
            );

        $staffRoleName =
            strtolower(
                $staffRole?->name ?? ''
            );

        if (
            str_contains(
                $staffRoleName,
                'therapist'
            )
        ) {

            $role = 'therapist';

        }

        if (
            str_contains(
                $staffRoleName,
                'admin'
            )
        ) {

            $role = 'admin';

        }

        if ($staff->user_id) {
            $user = User::find($staff->user_id);

            if ($user) {
                $user->update([
                    'name' => $staff->name,
                    'email' => $staff->email,
                    'role' => $role,
                ]);
            }
        }

        return response()->json([

            'message' => 'Staff updated successfully',

            'data' => $staff->load([
                'staffRole:id,name',
                'status:id,name',
            ]),

        ]);
    }

    public function destroy($id)
    {
        $staff = Staff::with(['status', 'role'])
            ->find($id);

        if (! $staff) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $staff->delete($id);

        return response()->json(['message' => 'Deleted']);
    }
}
