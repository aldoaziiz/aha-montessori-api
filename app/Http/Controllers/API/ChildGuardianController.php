<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\Guardian;
use Illuminate\Http\Request;

class ChildGuardianController extends Controller
{
    // ======================
    // ATTACH
    // ======================

    public function store(
        Request $request,
        Child $child
    ) {
        $request->validate([
            'guardian_id' => 'required|exists:guardians,id',
            'guardian_role_id' => 'required|exists:guardian_roles,id',
        ]);

        $exists = $child->guardians()
            ->where(
                'guardian_id',
                $request->guardian_id
            )
            ->exists();

        if ($exists) {

            return response()->json([
                'message' => 'Guardian already attached to this child.',
            ], 422);
        }

        $child->guardians()
            ->attach([
                $request->guardian_id => [
                    'guardian_role_id' => $request->guardian_role_id,
                ],
            ]);

        return response()->json([
            'message' => 'Guardian attached successfully',
        ]);
    }

    // ======================
    // DETACH
    // ======================

    public function destroy(
        Child $child,
        Guardian $guardian
    ) {
        // ======================
        // MINIMUM 1 GUARDIAN
        // ======================

        if (
            $child->guardians()
                ->count() <= 1
        ) {

            return response()->json([
                'message' => 'Child must have at least one guardian.',
            ], 422);
        }

        // ======================
        // DETACH
        // ======================

        $child->guardians()
            ->detach($guardian->id);

        return response()->json([
            'message' => 'Guardian removed successfully',
        ]);
    }
}
