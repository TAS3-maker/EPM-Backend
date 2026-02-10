<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventHolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'start_date'      => $this->start_date?->format('Y-m-d'),
            'end_date'        => $this->end_date?->format('Y-m-d'),
            'type'            => $this->type,
            'description'     => $this->description,
            'start_time'      => $this->start_time,
            'end_time'        => $this->end_time,
            'halfday_period'  => $this->halfday_period,
            'created_at'      => $this->created_at,
        ];
    }
}