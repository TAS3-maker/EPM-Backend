<?php

namespace App\Services;

use App\Models\DayOverride;
use App\Models\LeavePolicy;
use App\Models\LeaveCredit;
use App\Models\User;
use App\Models\WeeklyWorkingDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Request;

class LeaveCreditService
{
    public function processApprovedLeave(LeavePolicy $leave)
    {
        return DB::transaction(function () use ($leave) {
            $credit = LeaveCredit::where('user_id', $leave->user_id)->first();
            if (!$credit) {
                throw new \Exception('Leave credit record not found');
            }

            $calculation = $this->calculateLeaveDays($leave);
            $totalDays   = $calculation['total_days'];
            $actualDays   = $calculation['actual_days'];
            $sandwich    = $calculation['sandwich_days'];

            $paidDays = 0;
            $unpaidDays = 0;

            //NOTICE PERIOD → All unpaid
            if ($credit->employment_status === 'notice') {
                $credit->notice_period_days += $totalDays;
                $unpaidDays = $totalDays;
            }

            // PROVISIONAL (3 paid allowed total)
            //elseif ($credit->employment_status === 'provisional') {

            //$limit = $credit->provisional_leave_limit ?? 3;
            // Track total actual leave taken (exclude sandwich)
            //$newTotalTaken = $credit->provisional_leave_taken + $actualDays;

            /* if ($credit->provisional_leave_taken < $limit) {

                    $remaining = $limit - $credit->provisional_leave_taken;
                    if ($actualDays <= $remaining) {
                        $paidDays = $actualDays;
                    } else {
                        $paidDays = $remaining;
                        $unpaidDays = $actualDays - $remaining;
                    }
                } else {
                    // Already crossed limit → all unpaid
                    $unpaidDays = $actualDays;
                } */

            /*HANDLE EXTENDED MONTHS*/
            /*  if ($newTotalTaken > $limit) {
                    $exceededDays = $newTotalTaken - $limit;

                    // Example Rule: Every 1 extra leave = 1 month extension
                    $credit->provisional_extended_months = ($credit->provisional_extended_months ?? 0) + $exceededDays;
                }
                // Always update total provisional leave taken
                $credit->provisional_leave_taken = $newTotalTaken; */
            //     $credit->save();
            // }

            // appointed
            elseif ($credit->employment_status === 'appointed') {

                $start = Carbon::parse($leave->start_date);
                $totalWorkingDays = $this->getWorkingDaysInMonth($start);
                // Leaves already approved this month
                $approvedLeaveDays = LeavePolicy::where('user_id', $leave->user_id)
                    ->where('status', 'Approved')
                    ->where('id', '!=', $leave->id)
                    ->whereMonth('start_date', $start->month)
                    ->whereYear('start_date', $start->year)
                    ->sum('deducted_days');
                $workedDaysThisMonth = $totalWorkingDays - $approvedLeaveDays;
                $monthlyLimit = $credit->paid_leaves ?? 1;
                $remainingPaid = max(0, $monthlyLimit - $approvedLeaveDays);
                // 20 working days rule
                if ($workedDaysThisMonth < 20) {
                    $unpaidDays = $actualDays;
                } else {
                    $leaveToProcess = $actualDays + $sandwich;

                    if ($remainingPaid > 0) {
                        if ($leaveToProcess <= $remainingPaid) {
                            $paidDays = $leaveToProcess;
                        } else {
                            $paidDays = $remainingPaid;
                            $unpaidDays = $leaveToProcess - $remainingPaid;
                        }
                    } else {
                        $unpaidDays = $leaveToProcess;
                    }
                }
            }

            $leave->deducted_days = $totalDays;
            $leave->sandwich_extra_days = $sandwich;
            $leave->save();

            return [
                'total_days'   => $totalDays,
                'paid_days'    => $paidDays,
                'unpaid_days'  => $unpaidDays,
                'sandwich_days' => $sandwich
            ];
        });
    }
    public static function isWorkingDay(Carbon $date)
    {
        $override = DayOverride::whereDate('date', $date)->first();

        if ($override) {
            return (bool) $override->is_working;
        }

        $weekly = WeeklyWorkingDay::where('day_of_week', $date->dayOfWeek)->first();

        return $weekly ? (bool) $weekly->is_working : true;
    }
    public static function isNonWorking($date)
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

        return $weekly ? !$weekly->is_working : false;
    }
    public static function getAdjacentNonWorkingBlock(Carbon $date)
    {
        $days = 0;
        $current = $date->copy();

        while (!self::isWorkingDay($current)) {
            $days++;
            $current->addDay();
        }

        return $days;
    }
    public static function calculateLeaveDays(LeavePolicy $leave)
    {
        $start = Carbon::parse($leave->start_date);
        $end   = Carbon::parse($leave->end_date);

        $workHoursPerDay = 8.5;

        $leaveDays = 0;
        $sandwich = 0;

        $actualHours = 0;
        $sandwichHours = 0;

        $startTime = null;
        $endTime = null;

        // -------- Determine leave value --------

        if ($leave->leave_type === 'Full Leave') {
            $leaveDays = 1;
            $actualHours = $workHoursPerDay;
        }

        if ($leave->leave_type === 'Half Day') {
            $leaveDays = 0.5;
            $actualHours = $workHoursPerDay / 2;
        }

        if ($leave->leave_type === 'Short Leave' && $leave->hours) {

            [$startStr, $endStr] = explode(' to ', $leave->hours);

            $startTime = Carbon::parse($leave->start_date . ' ' . trim($startStr));
            $endTime   = Carbon::parse($leave->start_date . ' ' . trim($endStr));

            // calculate exact minutes
            $actualMinutes = $startTime->diffInMinutes($endTime);

            // convert to hours (no rounding)
            $actualHours = $actualMinutes / 60;

            $leaveDays = round($actualHours / $workHoursPerDay, 2);
        }

        if ($leave->leave_type === 'Multiple Days Leave') {

            $current = $start->copy();

            while ($current->lte($end)) {

                if (!self::isNonWorking($current)) {
                    $leaveDays++;
                    $actualHours += $workHoursPerDay;
                }

                $current->addDay();
            }
        }

        // -------- Sandwich Detection --------

        $prevDay = $start->copy()->subDay();
        $nextDay = $end->copy()->addDay();

        $touchesNonWorkingBlock = false;

        if ($leave->leave_type === 'Short Leave' && $leave->hours) {

            [$startStr, $endStr] = explode(' to ', $leave->hours);

            $startTime = Carbon::parse($leave->start_date . ' ' . trim($startStr));
            $endTime   = Carbon::parse($leave->start_date . ' ' . trim($endStr));

            $workStart = Carbon::parse($leave->start_date . ' 10:00');
            $workEnd   = Carbon::parse($leave->start_date . ' 19:00');

            // touching office boundary
            if (
                ($endTime->gte($workEnd) && self::isNonWorking($nextDay)) ||
                ($startTime->lte($workStart) && self::isNonWorking($prevDay))
            ) {
                $sandwich = $leaveDays;
                $touchesNonWorkingBlock = true;
            }
        } else {

            // Normal sandwich rule for full / half / multi leave
            if (self::isNonWorking($prevDay) || self::isNonWorking($nextDay)) {
                $touchesNonWorkingBlock = true;
            }
        }

        if ($touchesNonWorkingBlock) {
            $sandwich = $leaveDays;
        }

        // -------- Convert Sandwich to Hours --------

        $sandwichHours = round($sandwich * $workHoursPerDay, 2);
        $totalHours = round($actualHours + $sandwichHours , 2);

        return [
            'actual_days'   => $leaveDays,
            'sandwich_days' => $sandwich,
            'total_days'    => $leaveDays + $sandwich,

            'actual_hours'   => round($actualHours, 2),
            'sandwich_hours' => $sandwichHours,
            'total_hours'    => $totalHours,

            'touchesNonWorkingBlock' => $touchesNonWorkingBlock,
            'startTime' => $startTime,
            'endTime'   => $endTime,
        ];
    }
    public static function getWorkingDaysInMonth(Carbon $date)
    {
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth   = $date->copy()->endOfMonth();

        $workingDays = 0;

        // preload monthly overrides
        $overrides = DayOverride::whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get()
            ->keyBy(function ($item) {
                return Carbon::parse($item->date)->toDateString();
            });

        // preload weekly rules
        $weeklyRules = WeeklyWorkingDay::pluck('is_working', 'day_of_week');

        $current = $startOfMonth->copy();

        while ($current->lte($endOfMonth)) {

            $dateKey = $current->toDateString();

            // 1️⃣ Check override first
            if (isset($overrides[$dateKey])) {

                if ($overrides[$dateKey]->is_working) {
                    $workingDays++;
                }
            } else {

                // 2️⃣ fallback to weekly rule
                $weekday = $current->dayOfWeek;

                if (isset($weeklyRules[$weekday]) && $weeklyRules[$weekday]) {
                    $workingDays++;
                }
            }

            $current->addDay();
        }

        return $workingDays;
    }
    /**
     * Monthly Cron: run on 1st of every month
     */
    /* public function resetLeaveCreditByHR(User $user)
    {
        $now = Carbon::now();
        $month = $now->month;
        $year  = $now->year;

        $credit = LeaveCredit::firstOrCreate([
            'user_id' => $user->id,
        ]);

        // Reset if 12 month cycle completed
        if ($credit->cycle_month >= 12) {
            $credit->carry_forward = 0;
            $credit->cycle_month = 0;
        }

        if ($user->employment_status == 'appointed' && !$credit->is_notice_period) {
            $credit->monthly_credit = 1;
        }

        $credit->cycle_month += 1;
        $credit->save();
    } */
}
