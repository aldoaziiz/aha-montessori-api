<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GuardianResource extends JsonResource
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
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'id_number' => $this->id_number,
            'occupation' => $this->occupation,
            'social_media' => $this->social_media,

            'role' => $this->whenLoaded('role', function () {
                return [
                    'id' => $this->role->id,
                    'name' => $this->role->name,
                ];
            }),

            'status' => $this->whenLoaded('status', function () {
                return [
                    'id' => $this->status->id,
                    'name' => $this->status->name,
                ];
            }),

            'children' => $this->whenLoaded('children', function () {
                return $this->children->map(function ($c) {
                    return [
                        'id' => $c->id,
                        'name' => $c->name,
                    ];
                });
            }),
        ];
    }
}
