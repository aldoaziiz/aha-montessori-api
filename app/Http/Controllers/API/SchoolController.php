<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolResource;
use App\Models\School;

class SchoolController extends Controller
{
    public function index()
    {
        $data = School::orderBy('name', 'asc')->get();

        return SchoolResource::collection($data);
    }
}
