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
        $sessionSummary = $this->session_summary;

        return [
            'id' => $this->id,
            'registration_number' => $this->registration_number,
            'created_at' => $this->created_at,
            'total_session' => $this->total_session,
            'session_started_at' => $this->session_started_at?->toDateString(),
            'session_expired_at' => $this->session_expired_at?->toDateString(),
            'session_summary' => [
                'total_session' => $sessionSummary['total_session'],
                'used_session' => $sessionSummary['used_session'],
                'remaining_session' => $sessionSummary['remaining_session'],
                'session_started_at' => $this->session_started_at?->toDateString(),
                'session_expired_at' => $this->session_expired_at?->toDateString(),
                'is_session_expired' => $sessionSummary['is_session_expired'],
            ],

            'child' => [
                'id' => $this->child->id,
                'name' => $this->child->name,
                'birth_date' => $this->child->birth_date,
                'allergy_history' => $this->child->allergy_history,
                'special_condition' => $this->child->special_condition,
                'under_therapy' => $this->child->under_therapy,

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
