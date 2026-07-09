<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicResource;
use App\Models\Clinic;

class ClinicController extends Controller
{
    public function index()
    {
        $data = Clinic::all();

        return ClinicResource::collection($data);
    }
}
