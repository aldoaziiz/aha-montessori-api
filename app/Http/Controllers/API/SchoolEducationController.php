<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolEducationResource;
use App\Models\SchoolEducation;

class SchoolEducationController extends Controller
{
    public function index()
    {
        $data = SchoolEducation::all();

        return SchoolEducationResource::collection($data);
    }
}
