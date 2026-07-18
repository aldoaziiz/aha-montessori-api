<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TherapySessionStatus;

class TherapySessionStatusController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => TherapySessionStatus::orderBy('id')->get(),
        ]);
    }
}
