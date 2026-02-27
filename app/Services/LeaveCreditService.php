<?php

namespace App\Services;

use App\Models\LeavePolicy;
use App\Models\LeaveCredit;
use App\Models\User;
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
            elseif ($credit->employment_status === 'provisional') {

                $limit = $credit->provisional_leave_limit ?? 3;
                // Track total actual leave taken (exclude sandwich)
                $newTotalTaken = $credit->provisional_leave_taken + $actualDays;

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
                if ($newTotalTaken > $limit) {
                    $exceededDays = $newTotalTaken - $limit;

                    // Example Rule: Every 1 extra leave = 1 month extension
                    $credit->provisional_extended_months = ($credit->provisional_extended_months ?? 0) + $exceededDays;
                }
                // Always update total provisional leave taken
                $credit->provisional_leave_taken = $newTotalTaken;
                $credit->save();
            }

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

    private function calculateLeaveDays(LeavePolicy $leave)
    {
        $start = Carbon::parse($leave->start_date);
        $end   = Carbon::parse($leave->end_date);
        $workHoursPerDay = 8.5;

        /*SHORT LEAVE*/
        if ($leave->leave_type === 'Short Leave') {
            $decimalDays = $leave->total_hours / $workHoursPerDay;
            return [
                'total_days'    => round($decimalDays, 2),
                'actual_days'   => round($decimalDays, 2),
                'sandwich_days' => 0
            ];
        }
        /*HALF DAY*/
        if ($leave->leave_type === 'Half Day') {
            $sandwich = 0;
            // Friday Afternoon
            if ($start->isFriday() && strtolower($leave->halfday_period) === 'afternoon') {
                $sandwich = 0.5;
            }

            // Monday Morning
            if ($start->isMonday() && strtolower($leave->halfday_period) === 'morning') {
                $sandwich = 0.5;
            }

            return [
                'total_days'    => 0.5 + $sandwich,
                'actual_days'   => 0.5,
                'sandwich_days' => $sandwich
            ];
        }

        /*FULL / MULTIPLE DAYS*/
        $actualDays = 0;
        $sandwich = 0;
        $includesFriday = false;
        $includesMonday = false;
        $current = $start->copy();

        while ($current->lte($end)) {

            if (!$current->isSaturday() && !$current->isSunday()) {
                $actualDays++;
            }
            if ($current->isFriday()) {
                $includesFriday = true;
            }
            if ($current->isMonday()) {
                $includesMonday = true;
            }
            $current->addDay();
        }

        if (in_array($leave->leave_type, ['Full Leave', 'Multiple Days Leave'])) {
            if ($includesFriday || $includesMonday) {
                $sandwich = 1.0;
            }
        }

        return [
            'total_days'    => $actualDays + $sandwich,
            'actual_days'   => $actualDays,
            'sandwich_days' => $sandwich
        ];
    }
    public static function getWorkingDaysInMonth(Carbon $date)
    {
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth   = $date->copy()->endOfMonth();

        $workingDays = 0;
        $current = $startOfMonth->copy();

        while ($current->lte($endOfMonth)) {

            if (!$current->isSaturday() && !$current->isSunday()) {
                $workingDays++;
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
