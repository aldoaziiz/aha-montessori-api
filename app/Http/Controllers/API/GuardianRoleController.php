<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuardianRoleResource;
use App\Models\GuardianRole;

class GuardianRoleController extends Controller
{
    public function index()
    {
        $data = GuardianRole::orderBy('id', 'asc')->get();

        return GuardianRoleResource::collection($data);
    }
}
