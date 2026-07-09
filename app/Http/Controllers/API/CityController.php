<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Models\City;

class CityController extends Controller
{
    public function index()
    {
        $data = City::orderBy('name', 'asc')->get();

        return CityResource::collection($data);
    }
}
