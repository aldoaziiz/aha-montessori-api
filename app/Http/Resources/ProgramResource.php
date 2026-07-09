<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'session_count' => $this->session_count,
            'clinic' => $this->whenLoaded('clinic', function () {
                return [
                    'id' => $this->clinic->id,
                    'name' => $this->clinic->name,
                ];
            }),
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                ];
            }),
            'status' => $this->whenLoaded('status', function () {
                return [
                    'id' => $this->status->id,
                    'name' => $this->status->name,
                ];
            }),
            'payer' => $this->whenLoaded('payer', function () {
                return [
                    'id' => $this->payer->id,
                    'name' => $this->payer->name,
                ];
            }),
        ];
    }
}
