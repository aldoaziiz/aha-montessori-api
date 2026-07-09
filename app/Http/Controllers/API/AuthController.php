<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // ======================
    // LOGIN
    // ======================

    public function login(
        Request $request
    ) {

        $validated =
            $request->validate([

                'email' => 'required|email',

                'password' => 'required',

            ]);

        // ======================
        // FIND USER
        // ======================

        $user = User::query()

            ->with([

                'guardian.children',

                'staff.staffRole',

                'staff.status',

            ])

            ->where(
                'email',
                $validated['email']
            )

            ->first();

        if (
            ! $user ||
            ! Hash::check(
                $validated['password'],
                $user->password
            )
        ) {

            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        // ======================
        // REMOVE OLD TOKENS
        // ======================

        $user->tokens()->delete();

        // ======================
        // CREATE TOKEN
        // ======================

        $token = $user
            ->createToken('auth_token')
            ->plainTextToken;

        // ======================
        // RESPONSE
        // ======================

        return response()->json([

            'message' => 'Login success',

            'token' => $token,

            'user' => $user,

        ]);
    }

    // ======================
    // ME
    // ======================

    public function me(
        Request $request
    ) {

        $user = User::query()

            ->with([

                'guardian.children',

                'staff.staffRole',

                'staff.status',

            ])

            ->find(
                $request->user()->id
            );

        return response()->json(
            $request->user()->load([

                'guardian.children',

                'staff.staffRole',

            ])
        );
    }

    // ======================
    // LOGOUT
    // ======================

    public function logout(
        Request $request
    ) {

        $request->user()
            ->currentAccessToken()
            ->delete();

        return response()->json([
            'message' => 'Logout success',
        ]);
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:6|confirmed',
        ]);

        $user = auth()->user();

        if (! Hash::check(
            $validated['current_password'],
            $user->password
        )) {

            return response()->json([
                'message' => 'Current password is incorrect',
            ], 422);

        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }
}
