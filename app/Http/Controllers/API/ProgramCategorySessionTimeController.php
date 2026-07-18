<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgramCategory;

class ProgramCategorySessionTimeController extends Controller
{
    public function index(ProgramCategory $programCategory)
    {
        return response()->json([
            'data' => $programCategory
                ->sessionTimes()
                ->get([
                    'id',
                    'session_order',
                    'session_name',
                    'start_time',
                    'end_time',
                ]),
        ]);
    }
}
