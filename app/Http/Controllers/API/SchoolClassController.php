<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolClassResource;
use App\Models\SchoolClass;

class SchoolClassController extends Controller
{
    public function index()
    {
        $data = SchoolClass::all();

        return SchoolClassResource::collection($data);
    }
}
