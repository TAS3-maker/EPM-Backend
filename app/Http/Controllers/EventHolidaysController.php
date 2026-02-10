<?php

namespace App\Http\Controllers;

use App\Models\EventHoliday;
use App\Http\Resources\EventHolidayResource;
use Illuminate\Http\Request;

class EventHolidaysController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => EventHolidayResource::collection(
                EventHoliday::latest()->get()
            )
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date'   => 'required|date|after_or_equal:start_date',
                'type'       => 'required|in:Full Holiday,Short Holiday,Half Holiday,Multiple Holiday',
                'description' => 'required|string',
                'start_time' => 'required_if:type,Short Holiday|string|nullable',
                'end_time'   => 'required_if:type,Short Holiday|string|nullable',
                'halfday_period' => [
                    'nullable',
                    function ($attribute, $value, $fail) use ($request) {
                        if ($request->type === 'Half Holiday') {
                            if (!$value) {
                                $fail('The halfday_period field is required when type is Half Holiday.');
                            } elseif (!in_array(strtolower($value), ['morning', 'afternoon'])) {
                                $fail('Halfday period must be either morning or afternoon.');
                            }
                        }
                    }
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
                'input'   => $request->all(),
            ], 422);
        }
        $holiday = EventHoliday::create($validated);

        return new EventHolidayResource($holiday);
    }

    public function show(EventHoliday $eventHoliday)
    {
        return new EventHolidayResource($eventHoliday);
    }

    public function update(Request $request, EventHoliday $eventHoliday)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date'   => 'required|date|after_or_equal:start_date',
                'type'       => 'required|in:Full Holiday,Short Holiday,Half Holiday,Multiple Holiday',
                'description' => 'required|string',
                'start_time' => 'required_if:type,Short Holiday|string|nullable',
                'end_time'   => 'required_if:type,Short Holiday|string|nullable',
                'halfday_period' => [
                    'nullable',
                    function ($attribute, $value, $fail) use ($request) {
                        if ($request->type === 'Half Holiday') {
                            if (!$value) {
                                $fail('The halfday_period field is required when type is Half Holiday.');
                            } elseif (!in_array(strtolower($value), ['morning', 'afternoon'])) {
                                $fail('Halfday period must be either morning or afternoon.');
                            }
                        }
                    }
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
                'input'   => $request->all(),
            ], 422);
        }

        $eventHoliday->update($validated);

        return new EventHolidayResource($eventHoliday);
    }

    public function destroy(EventHoliday $eventHoliday)
    {
        $eventHoliday->delete();

        return response()->json([
            'message' => 'Event holiday deleted successfully'
        ]);
    }
}