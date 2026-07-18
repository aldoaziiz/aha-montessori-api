<?php

namespace App\Http\Resources;

use App\Models\GuardianRole;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
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
            'registration_number' => $this->registration_number,
            'created_at' => $this->created_at,

            'child' => [
                'id' => $this->child->id,
                'name' => $this->child->name,
                'birth_date' => $this->child->birth_date,

                'guardians' => $this->child->guardians->map(function ($g) {
                    $role = GuardianRole::find($g->pivot->guardian_role_id);

                    return [
                        'id' => $g->id,
                        'name' => $g->name,
                        'phone' => $g->phone,
                        'guardian_role' => [
                            'id' => $role?->id,
                            'name' => $role?->name,
                        ],
                    ];
                }),
            ],

            'clinic' => $this->clinic ? [
                'id' => $this->clinic->id,
                'name' => $this->clinic->name,
            ] : null,

            'programs' => $this->programs->map(function ($program) {

                return [
                    'id' => $program->id,
                    'name' => $program->name,

                    // snapshot registration
                    'price' => $program->pivot->price,
                    'learning_period_months' => $program->pivot->learning_period_months,

                    // master program
                    'session_count' => $program->session_count,

                    'program_category' => [
                        'id' => $program->category?->id,
                        'name' => $program->category?->name,
                    ],
                ];
            }),

            'payer' => $this->payer ? [
                'id' => $this->payer->id,
                'name' => $this->payer->name,
            ] : null,

            'payment_status' => $this->billing
                ? [
                    'id' => $this->billing->paymentStatus?->id,
                    'name' => $this->billing->paymentStatus?->name,
                ]
                : [
                    'id' => 0,
                    'name' => 'Not Generated',
                ],

            'complaint' => $this->complaint,
        ];
    }
}
