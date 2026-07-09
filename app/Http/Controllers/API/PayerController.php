<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayerResource;
use App\Models\Payer;

class PayerController extends Controller
{
    public function index()
    {
        $data = Payer::all();

        return PayerResource::collection($data);
    }
}
