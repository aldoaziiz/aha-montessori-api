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
            'price_per_session' => $this->whenLoaded('track', fn () => $this->track?->price_per_session),
            'monthly_price' => $this->whenLoaded('track', fn () => $this->track
                ? (float) $this->track->price_per_session * (int) $this->session_count
                : $this->price),
            'session_count' => $this->session_count,
            'is_registration_fee' => (bool) $this->is_registration_fee,
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
                    'supports_tracks' => (bool) $this->category->supports_tracks,
                ];
            }),
            'track' => $this->whenLoaded('track', function () {
                return $this->track ? [
                    'id' => $this->track->id,
                    'subject_name' => $this->track->subject_name,
                    'delivery_mode' => $this->track->delivery_mode,
                ] : null;
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
