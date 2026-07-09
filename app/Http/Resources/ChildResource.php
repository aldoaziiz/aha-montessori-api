<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ChildResource extends JsonResource
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
            'id_number' => $this->id_number,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'birth_date' => $this->birth_date,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'address' => $this->address,
            'created_at' => $this->created_at,

            'status' => $this->whenLoaded('status', function () {
                return [
                    'id' => $this->status->id,
                    'name' => $this->status->name,
                ];
            }),

            'guardians' => $this->whenLoaded('guardians', function () {
                return $this->guardians->map(function ($g) {
                    return [
                        'id' => $g->id,
                        'name' => $g->name,
                        'phone' => $g->phone,
                        'guardian_role' => [
                            'id' => $g->pivot->guardian_role_id,
                            'name' => optional($g->pivot->role)->name,
                        ],
                    ];
                });
            }),

            'school' => $this->whenLoaded('school', function () {
                return [
                    'id' => $this->school->id,
                    'name' => $this->school->name,
                ];
            }),

            'school_education' => $this->whenLoaded('schoolEducation', function () {
                return [
                    'id' => $this->schoolEducation->id,
                    'name' => $this->schoolEducation->name,
                ];
            }),

            'school_class' => $this->whenLoaded('schoolClass', function () {
                return [
                    'id' => $this->schoolClass->id,
                    'name' => $this->schoolClass->name,
                ];
            }),

            'birthplace' => $this->whenLoaded('birthplace', function () {
                return [
                    'id' => $this->birthplace->id,
                    'name' => $this->birthplace->name,
                ];
            }),

            'hometown' => $this->whenLoaded('hometown', function () {
                return [
                    'id' => $this->hometown->id,
                    'name' => $this->hometown->name,
                ];
            }),
        ];
    }
}
