<?php

namespace App\Http\Controllers;

use App\Http\Helpers\ApiResponse;
use App\Models\LeaveCredit;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use League\Config\Exception\ValidationException;

class LeaveCreditController extends Controller
{
    public function index(Request $request)
    {
        /* $month = $request->month ?? now()->month;
        $year  = $request->year  ?? now()->year; */
        /*  $leaveCredits = LeaveCredit::with([
            'user:id,name',
            'user.leaves' => function ($query) use ($month, $year) {
                $query->where('status', 'Approved')->where('employment_period', 'appointed')
                    ->whereMonth('start_date', $month)
                    ->whereYear('start_date', $year);
            }
        ])->latest()->get(); */
        $currentUser = auth()->user();
        $query =  LeaveCredit::with([
            'user:id,name',
            'user.leaves'
        ])->whereHas('user', function ($q) {
            $q->where('is_active', 1);
        });
        if ($currentUser->hasAnyRole([1, 2, 3, 4])) {
            // No additional filter (can see all)
        } else {
            $query->where('user_id', $currentUser->id);
        }
        $leaveCredits = $query->latest()->get();
        // Append calculated fields
        $leaveCredits->transform(function ($credit) {
            $month = $credit->month;
            $year  = $credit->year;
            $startDate = Carbon::create($credit->year, $credit->month, 1)->startOfMonth();
            $endDate   = Carbon::create($credit->year, $credit->month, 1)->endOfMonth();
            $approvedLeaves = $credit->user->leaves()
                ->where('status', 'Approved')
                ->where(function ($query) {
                    $query->where('employment_period', 'appointed')
                        ->orWhereNull('employment_period');
                })
                ->whereBetween('start_date', [$startDate, $endDate])
                ->get();
            $provisionalLeaves = $credit->user->leaves()
                ->where('status', 'Approved')
                ->where(function ($query) {
                    $query->where('employment_period', 'provisional');
                })
                ->get();

            $credit->leave_application_count = $approvedLeaves->count();
            $credit->provisional_leave_taken = (float) $provisionalLeaves->sum('deducted_days');
            $credit->provisional_extended_months = 0;

            if ($credit->employment_status === 'provisional') {
                $credit->user->setRelation('leaves', $provisionalLeaves);
            } else {
                $credit->user->setRelation('leaves', $approvedLeaves);
            }
            /**provisional extended month calculation */
            $limit = $credit->provisional_leave_limit ?? 3;
            if ($credit->provisional_leave_taken > $limit) {
                $exceededDays = $credit->provisional_leave_taken - $limit;
                $credit->provisional_extended_months = $exceededDays;
                // $credit->provisional_extended_months = ($credit->provisional_extended_months ?? 0) + $exceededDays;
            }
            /**end provisional extended month calculation */

            $approvedLeaveHours = $this->calculateLeaveHours($approvedLeaves);

            $approvedLeaveDays = round($approvedLeaveHours / 8.5, 2);
            $sandwichDays = $approvedLeaves->sum(function ($leave) {
                return (float) ($leave->sandwich_extra_days ?? 0);
            });
            $deductedDays = $approvedLeaveDays + $sandwichDays;


            /**working hours */
            $sandwich_hours = $approvedLeaves->sum(function ($leave) {
                return (float) ($leave->sandwich_extra_days ?? 0);
            });


            $monthlyLimitHours = ($credit->paid_leaves ?? 0) * 8.5;

            $totalAllowedHours =
                (($credit->paid_leaves ?? 0) +
                    ($credit->carry_forward_balance ?? 0)) * 8.5;

            $credit->remaining_paid_leave_hours =
                max(0, $totalAllowedHours - $approvedLeaveHours);

            $credit->leave_taken_hours = $approvedLeaveHours;
            $credit->total_deducted_hours = $approvedLeaveHours + ($sandwich_hours * 8.5);
            // $credit->sandwich_hours = $sandwich_hours * 8.5;
            //Leave Taken (remaining paid leave for month)
            $credit->leave_days = $approvedLeaveDays;
            $credit->deducted_days = $deductedDays;
            $dateForMonth = Carbon::create($year, $month, 1);
            // Holiday Calculation
            // ===============================

            $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
            $monthEnd   = Carbon::create($year, $month, 1)->endOfMonth();

            // Get holidays overlapping this month
            $holidays = \App\Models\EventHoliday::where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('start_date', [$monthStart, $monthEnd])
                    ->orWhereBetween('end_date', [$monthStart, $monthEnd])
                    ->orWhere(function ($q) use ($monthStart, $monthEnd) {
                        $q->where('start_date', '<=', $monthStart)
                            ->where('end_date', '>=', $monthEnd);
                    });
            })->get();

            $totalHolidayDays = 0;
            $totalHolidayHours = 0;
            foreach ($holidays as $holiday) {

                $holidayStart = Carbon::parse($holiday->start_date);
                $holidayEnd   = $holiday->end_date
                    ? Carbon::parse($holiday->end_date)
                    : $holidayStart;

                $period = \Carbon\CarbonPeriod::create($holidayStart, $holidayEnd);

                foreach ($period as $date) {

                    if ($date->lt($monthStart) || $date->gt($monthEnd)) {
                        continue;
                    }

                    if ($date->isSaturday() || $date->isSunday()) {
                        continue; // skip weekends
                    }

                    switch ($holiday->type) {

                        case 'Full Holiday':
                        case 'Multiple Holiday':
                            $totalHolidayDays += 1;
                            $totalHolidayHours += 8.5;
                            break;

                        case 'Half Holiday':
                            $totalHolidayDays += 0.5;
                            $totalHolidayHours += 4.25;
                            break;

                        case 'Short Holiday':
                            if ($holiday->start_time && $holiday->end_time) {
                                $start = Carbon::parse($holiday->start_time);
                                $end   = Carbon::parse($holiday->end_time);
                                $hours = $end->diffInMinutes($start) / 60;

                                $totalHolidayHours += $hours;
                            }
                            break;
                    }
                }
            }

            // Attach holiday data
            $credit->holiday_days = $totalHolidayDays;
            $credit->holiday_hours = $totalHolidayHours;
            $expectedWorkingDays =
                LeaveCreditService::getWorkingDaysInMonth($dateForMonth);

            /**working hours */
            $expectedWorkingHours = ($expectedWorkingDays * 8.5) - $totalHolidayHours;
            $credit->expected_working_hours = max(0, $expectedWorkingHours);
            $credit->worked_hours =
                max(0, $expectedWorkingHours - $approvedLeaveHours);
            // $credit->expected_working_days = $expectedWorkingDays;

            // Paid / Unpaid logic
            if ($approvedLeaveHours <= $monthlyLimitHours) {
                $credit->paid_hours = $approvedLeaveHours;
                $credit->unpaid_hours = 0;
            } else {
                $carryHours = (float) ($credit->carry_forward_balance ?? 0) * 8.5;
                $unpaidCalculation =
                    max(0, $credit->total_deducted_hours - $monthlyLimitHours - $carryHours);

                $credit->paid_hours = $credit->total_deducted_hours - $unpaidCalculation;
                $credit->unpaid_hours = $unpaidCalculation;
            }

            if ($credit->employment_status === 'notice' && $credit->notice_start_date) {
                $startDate = Carbon::parse($credit->notice_start_date);

                $basePeriodDays = (int) ($credit->notice_period_days ?? 0);

                // Initial notice end date (without extension)
                $initialEndDate = $startDate->copy()->addDays($basePeriodDays);

                // Get approved leaves during notice period
                $noticeLeaves = $credit->user->leaves()
                    ->where('status', 'Approved')
                    ->whereBetween('start_date', [
                        $startDate->toDateString(),
                        $initialEndDate->toDateString()
                    ])
                    ->get();

                // Total deducted days during notice
                $totalDeductedDays = (float) $noticeLeaves->sum('deducted_days');

                // Convert to whole number (HR rule)
                $extensionDays = (int) floor($totalDeductedDays);

                // Final recalculated notice period
                $finalNoticePeriod = $basePeriodDays + $extensionDays;

                $credit->notice_end_date = $startDate
                    ->copy()
                    ->addDays($finalNoticePeriod)
                    ->toDateString();
            } else {
                $credit->notice_end_date = null;
            }
            $credit->provisional_end_date = $credit->joining_date
                ? Carbon::parse($credit->joining_date)
                ->addDays($credit->provisional_days ?? 0)
                ->addDays(30 * ($credit->provisional_extended_months ?? 0))
                ->toDateString()
                : null;

            return $credit;
        });

        return response()->json([
            'success' => true,
            'data'    => $leaveCredits
        ]);
    }
    private function calculateLeaveHours($leaves)
    {
        /**working hours used here*/
        return $leaves->sum(function ($leave) {
            if ($leave->total_hours) return (float) $leave->total_hours;
            if ($leave->leave_type === 'Full Leave') return 8.5;
            if ($leave->leave_type === 'Half Day') return 4.25;

            if ($leave->leave_type === 'Short Leave' && $leave->start_time && $leave->end_time) {
                $start = Carbon::parse($leave->start_time);
                $end   = Carbon::parse($leave->end_time);
                return $end->diffInMinutes($start) / 60;
            }

            return 0;
        });
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'paid_leaves' => 'required|numeric|min:0',
            'bunch_time' => 'required|integer|min:1',
            'provisional_days' => 'required|integer|min:0',
            'joining_date' => 'required|date',
        ]);

        $leave = LeaveCredit::create($data);

        return response()->json([
            'message' => 'Leave credit created',
            'data' => $leave
        ], 201);
    }

    public function show($id)
    {
        $credit = LeaveCredit::with([
            'user:id,name',
            'user.leaves'
        ])->find($id);

        if (!$credit) {
            return response()->json([
                'success' => false,
                'message' => 'Leave credit not found'
            ], 404);
        }

        $month = $credit->month;
        $year  = $credit->year;

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate   = Carbon::create($year, $month, 1)->endOfMonth();

        $approvedLeaves = $credit->user->leaves()
            ->where('status', 'Approved')
            ->where(function ($query) {
                $query->where('employment_period', 'appointed')
                    ->orWhereNull('employment_period');
            })
            ->whereBetween('start_date', [$startDate, $endDate])
            ->get();

        $credit->user->setRelation('leaves', $approvedLeaves);
        $credit->leave_application_count = $approvedLeaves->count();

        $approvedLeaveHours = $this->calculateLeaveHours($approvedLeaves);

        $approvedLeaveDays = round($approvedLeaveHours / 8.5, 2);

        $sandwichDays = $approvedLeaves->sum(function ($leave) {
            return (float) ($leave->sandwich_extra_days ?? 0);
        });

        $deductedDays = $approvedLeaveDays + $sandwichDays;

        $monthlyLimitHours = ($credit->paid_leaves ?? 0) * 8.5;

        $totalAllowedHours =
            (($credit->paid_leaves ?? 0) +
                ($credit->carry_forward_balance ?? 0)) * 8.5;

        $credit->remaining_paid_leave_hours =
            max(0, $totalAllowedHours - $approvedLeaveHours);

        $credit->leave_taken_hours = $approvedLeaveHours;
        $credit->leave_days = $approvedLeaveDays;
        $credit->deducted_days = $deductedDays;

        /* ---------------- HOLIDAY CALCULATION ---------------- */

        $monthStart = $startDate;
        $monthEnd   = $endDate;

        $holidays = \App\Models\EventHoliday::where(function ($q) use ($monthStart, $monthEnd) {
            $q->whereBetween('start_date', [$monthStart, $monthEnd])
                ->orWhereBetween('end_date', [$monthStart, $monthEnd])
                ->orWhere(function ($q) use ($monthStart, $monthEnd) {
                    $q->where('start_date', '<=', $monthStart)
                        ->where('end_date', '>=', $monthEnd);
                });
        })->get();

        $totalHolidayDays = 0;
        $totalHolidayHours = 0;

        foreach ($holidays as $holiday) {

            $holidayStart = Carbon::parse($holiday->start_date);
            $holidayEnd   = $holiday->end_date
                ? Carbon::parse($holiday->end_date)
                : $holidayStart;

            $period = \Carbon\CarbonPeriod::create($holidayStart, $holidayEnd);

            foreach ($period as $date) {

                if ($date->lt($monthStart) || $date->gt($monthEnd)) {
                    continue;
                }

                if ($date->isSaturday() || $date->isSunday()) {
                    continue;
                }

                switch ($holiday->type) {

                    case 'Full Holiday':
                    case 'Multiple Holiday':
                        $totalHolidayDays += 1;
                        $totalHolidayHours += 8.5;
                        break;

                    case 'Half Holiday':
                        $totalHolidayDays += 0.5;
                        $totalHolidayHours += 4.25;
                        break;

                    case 'Short Holiday':
                        if ($holiday->start_time && $holiday->end_time) {
                            $start = Carbon::parse($holiday->start_time);
                            $end   = Carbon::parse($holiday->end_time);
                            $totalHolidayHours += $end->diffInMinutes($start) / 60;
                        }
                        break;
                }
            }
        }

        $credit->holiday_days = $totalHolidayDays;
        $credit->holiday_hours = $totalHolidayHours;

        $dateForMonth = Carbon::create($year, $month, 1);

        $expectedWorkingDays =
            LeaveCreditService::getWorkingDaysInMonth($dateForMonth);

        $expectedWorkingHours =
            ($expectedWorkingDays * 8.5) - $totalHolidayHours;

        $credit->expected_working_hours = max(0, $expectedWorkingHours);
        $credit->worked_hours =
            max(0, $expectedWorkingHours - $approvedLeaveHours);

        /* ---------------- PAID / UNPAID ---------------- */

        if ($approvedLeaveHours <= $monthlyLimitHours) {
            $credit->paid_hours = $approvedLeaveHours;
            $credit->unpaid_hours = 0;
        } else {
            $carryHours = (float) ($credit->carry_forward_balance ?? 0) * 8.5;

            $unpaidCalculation =
                max(0, $approvedLeaveHours - $monthlyLimitHours - $carryHours);

            $credit->paid_hours =
                $approvedLeaveHours - $unpaidCalculation;

            $credit->unpaid_hours = $unpaidCalculation;
        }

        /* ---------------- NOTICE PERIOD ---------------- */

        if ($credit->employment_status === 'notice' && $credit->notice_start_date) {

            $startDate = Carbon::parse($credit->notice_start_date);
            $basePeriodDays = (int) ($credit->notice_period_days ?? 0);

            $initialEndDate = $startDate->copy()->addDays($basePeriodDays);

            $noticeLeaves = $credit->user->leaves()
                ->where('status', 'Approved')
                ->whereBetween('start_date', [
                    $startDate->toDateString(),
                    $initialEndDate->toDateString()
                ])
                ->get();

            $totalDeductedDays =
                (float) $noticeLeaves->sum('deducted_days');

            $extensionDays = (int) floor($totalDeductedDays);

            $finalNoticePeriod =
                $basePeriodDays + $extensionDays;

            $credit->notice_end_date =
                $startDate->copy()
                ->addDays($finalNoticePeriod)
                ->toDateString();
        } else {
            $credit->notice_end_date = null;
        }

        /* ---------------- PROVISIONAL ---------------- */

        $credit->provisional_end_date =
            $credit->joining_date
            ? Carbon::parse($credit->joining_date)
            ->addDays($credit->provisional_days ?? 0)
            ->addDays(30 * ($credit->provisional_extended_months ?? 0))
            ->toDateString()
            : null;

        return response()->json([
            'success' => true,
            'data'    => $credit
        ]);
    }

    public function update(Request $request, $id)
    {
        try {
            $leaveCredit = LeaveCredit::findOrFail($id);
            try {
                // Validate manually so we can inspect result
                $validatedData = $request->validate([
                    'user_id' => 'sometimes|exists:users,id',
                    'employment_status' => 'sometimes|in:provisional,appointed,notice',
                    'cycle_start_date' => 'sometimes|date',
                    'carry_forward_balance'    => 'sometimes|numeric|min:0',
                    'provisional_leave_limit'  => 'sometimes|integer|min:0',
                    'provisional_leave_taken'  => 'sometimes|numeric|min:0',
                    'provisional_extended_months' => 'sometimes|integer|min:0',
                    'notice_start_date' => 'sometimes|nullable|date',
                    'paid_leaves' => 'sometimes|numeric|min:0',
                    'notice_period_days' => 'sometimes|numeric|min:0',
                    'bunch_time'  => 'sometimes|integer|min:1',
                    'provisional_days' => 'sometimes|integer|min:0',
                    'joining_date' => 'sometimes|date|before_or_equal:today',
                ]);
                if (empty($validatedData)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No data provided for update.'
                    ], 422);
                }
            } catch (ValidationException $e) {
                return ApiResponse::error('Validation Error', $e->errors(), 422);
            }
            // Update
            $leaveCredit->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Leave credit updated successfully',
                'data' => $leaveCredit->fresh()
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function generateLeaveCredits()
    {
        return DB::transaction(function () {
            $currentYear = now()->year;
            $currentMonth = now()->month;

            $cycleStart = Carbon::create($currentYear, 1, 1)->startOfDay();
            $cycleEnd   = Carbon::create($currentYear, 12, 31)->endOfDay();
            $activeUsers = User::where('is_active', 1)->get();

            $created = 0;
            $skipped = 0;

            foreach ($activeUsers as $user) {

                $credit = LeaveCredit::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'year' => $currentYear,
                        'month' => $currentMonth,
                        'employment_status' => 'appointed',
                        'cycle_start_date' => $cycleStart,
                        'provisional_days' => 90,
                        'joining_date' => $user->created_at,
                    ]
                );

                if ($credit->wasRecentlyCreated) {
                    $created++;
                }
            }
            return response()->json([
                'success' => true,
                'message' => 'Leave credits generated successfully',
                'year' => $currentYear,
                'month' => $currentMonth,
                'created_count' => $created
            ]);
        });
    }
    public function processMonthlyLeaveCycle(Request $request)
    {
        if ($request->filled('month') && $request->filled('year')) {
            $processMonth = (int) $request->month;
            $processYear  = (int) $request->year;
        } else {
            $currentDate  = now();
            $processMonth = $currentDate->month;
            $processYear  = $currentDate->year;
        }
        $count = 0;
        $credits = LeaveCredit::whereIn('employment_status', ['appointed', 'notice'])->get();

        foreach ($credits as $credit) {
            $credit->bunch_payble_balance = 0;
            if ($credit->month == $processMonth && $credit->year == $processYear) {
                continue;
            }

            $monthlyPaid   = (float) ($credit->paid_leaves ?? 0);
            $previousCarry = (float) ($credit->carry_forward_balance ?? 0);
            $bunchTime     = (int) ($credit->bunch_time ?? 12);

            // ==============================
            // Proper Batch Logic (Delayed Payout)
            // ==============================

            $processDate = \Carbon\Carbon::create($processYear, $processMonth, 1);
            $cycleStartDate = \Carbon\Carbon::parse($credit->cycle_start_date)
                ->startOfMonth();

            if ($processDate->lt($cycleStartDate)) {
                continue;
            }

            $bunchTime = (int) ($credit->bunch_time ?? 12);

            // Months since cycle started
            $monthsSinceStart = $cycleStartDate->diffInMonths($processDate);

            // Detect if PREVIOUS month was batch ending
            $isPayoutMonth = ($monthsSinceStart % $bunchTime) === 0 && $monthsSinceStart != 0;

            // Calculate Leave Taken
            $taken = (float) LeavePolicy::where('user_id', $credit->user_id)
                ->where('status', 'Approved')
                ->whereMonth('start_date', $processMonth)
                ->whereYear('start_date', $processYear)
                ->sum('deducted_days');

            $totalAvailable = $monthlyPaid + $previousCarry;
            $remaining      = max(0, $totalAvailable - $taken);

            if ($isPayoutMonth) {

                // Move full accumulated balance to payable
                $credit->bunch_payble_balance = $remaining;

                // Reset carry for new batch
                $credit->carry_forward_balance = 0;
            } else {

                // Normal accumulation month
                $credit->bunch_payble_balance = 0; // reset automatically after payout month
                $credit->carry_forward_balance = $remaining;
            }

            // Update Active Month
            $credit->month = $processMonth;
            $credit->year  = $processYear;

            $credit->save();
            $count++;
        }

        return response()->json([
            'success' => true,
            'processed_month' => $processMonth,
            'processed_year'  => $processYear,
            'total_updated'  => $count,
        ]);
    }
    public function destroy($id)
    {
        $leaveCredit = LeaveCredit::findOrFail($id);
        $leaveCredit->delete();

        return response()->json([
            'message' => 'Leave credit deleted'
        ]);
    }
}
