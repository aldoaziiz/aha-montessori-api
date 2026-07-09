<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffRoleResource;
use App\Models\StaffRole;

class StaffRoleController extends Controller
{
    public function index()
    {
        $data = StaffRole::orderBy('id', 'asc')->get();

        return StaffRoleResource::collection($data);
    }
}
