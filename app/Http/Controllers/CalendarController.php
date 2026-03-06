<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\WeeklyWorkingDay;
use App\Models\DayOverride;

class CalendarController extends Controller
{
    public function index()
    {
        return WeeklyWorkingDay::orderBy('day_of_week')->get();
    }
    public function setCalendarDate(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'is_working' => 'required|boolean',
            'reason' => 'nullable|string'
        ]);

        $override = DayOverride::updateOrCreate(
            ['date' => $request->date],
            [
                'is_working' => $request->is_working,
                'reason' => $request->reason
            ]
        );

        return response()->json([
            'message' => 'Calendar date configured',
            'data' => $override
        ]);
    }
    public function destroy($date)
    {
        DayOverride::whereDate('date', $date)->delete();

        return response()->json([
            'message' => 'Day removed successfully'
        ]);
    }
    public function isNonWorkingDay($date)
    {
        $date = Carbon::parse($date);

        $override = DayOverride::whereDate('date', $date)->first();

        if ($override) {
            return !$override->is_working;
        }

        $weekly = WeeklyWorkingDay::where(
            'day_of_week',
            $date->dayOfWeek
        )->first();

        if ($weekly) {
            return !$weekly->is_working;
        }

        if (in_array($date->dayOfWeek, [0, 6])) {
            return true;
        }

        return false;
    }


    public function checkDate(Request $request)
    {
        $date = $request->date;

        $isNonWorking = $this->isNonWorkingDay($date);

        return response()->json([
            'date' => $date,
            'day' => Carbon::parse($date)->format('l'),
            'is_non_working' => $isNonWorking
        ]);
    }


    public function getNonWorkingDays()
    {
        $weekly = WeeklyWorkingDay::where('is_working', 0)
            ->pluck('day_of_week');

        $overrides = DayOverride::where('is_working', 0)
            ->pluck('date');

        return response()->json([
            'weekly_non_working_days' => $weekly,
            'specific_non_working_dates' => $overrides
        ]);
    }


    public function sandwichDays(Request $request)
    {
        $start = Carbon::parse($request->start_date);
        $end   = Carbon::parse($request->end_date);

        $sandwichDays = [];

        $current = $start->copy()->addDay();

        while ($current->lte($end)) {

            if ($this->isNonWorkingDay($current)) {
                $sandwichDays[] = $current->toDateString();
            }

            $current->addDay();
        }

        return response()->json([
            'sandwich_days' => $sandwichDays,
            'count' => count($sandwichDays)
        ]);
    }
    public function updateWeeklyRule(Request $request)
    {
        $request->validate([
            'day_of_week' => 'required|integer|min:0|max:6',
            'is_working' => 'required|boolean'
        ]);

        $day = WeeklyWorkingDay::where('day_of_week', $request->day_of_week)->first();

        if (!$day) {
            return response()->json(['message' => 'Day not found'], 404);
        }

        $day->update([
            'is_working' => $request->is_working
        ]);

        return response()->json([
            'message' => 'Weekly rule updated',
            'data' => $day
        ]);
    }
    public function getMonthCalendar(Request $request)
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12'
        ]);

        $start = Carbon::create($request->year, $request->month, 1);
        $end = $start->copy()->endOfMonth();

        $calendar = [];

        $current = $start->copy();

        while ($current->lte($end)) {

            $override = DayOverride::whereDate('date', $current)->first();
            if ($override) {

                $isNonWorking = !$override->is_working;

                $reason = $override->reason ?? ($isNonWorking ? 'Non-working override' : 'Working override');
            } else {

                $weekly = WeeklyWorkingDay::where('day_of_week', $current->dayOfWeek)->first();

                $isNonWorking = $weekly ? !$weekly->is_working : false;

                $reason = $isNonWorking ? 'Weekly Off' : null;
            }
            $calendar[] = [
                'date' => $current->toDateString(),
                'day' => $current->format('l'),
                'is_non_working' => $this->isNonWorkingDay($current),
                'reason' => $reason
            ];

            $current->addDay();
        }

        return response()->json([
            'month' => $request->month,
            'year' => $request->year,
            'days' => $calendar
        ]);
    }
}
