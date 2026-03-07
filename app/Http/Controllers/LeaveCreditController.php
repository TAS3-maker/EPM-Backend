<?php

namespace App\Http\Controllers;

use App\Http\Helpers\ApiResponse;
use App\Models\DayOverride;
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
        $currentUser = auth()->user();
        $query = LeaveCredit::with([
            'user:id,name',
            'user.leaves'
        ])->whereHas('user', function ($q) {
            $q->where('is_active', 1);
        });

        if (!$currentUser->hasAnyRole([1, 2, 3, 4])) {
            $query->where('user_id', $currentUser->id);
        }

        $leaveCredits = $query->latest()->get();
        $calendarController = new \App\Http\Controllers\CalendarController();

        $leaveCredits->transform(function ($credit) use ($calendarController) {
            $month = $credit->month;
            $year  = $credit->year;
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate   = Carbon::create($year, $month, 1)->endOfMonth();

            $allApprovedLeaves = $credit->user->leaves()
                ->where('status', 'Approved')
                ->where(function ($q) {
                    $q->where('employment_period', 'appointed')
                        ->orWhereNull('employment_period');
                })
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                })
                ->get();

            $monthLeaveDays = 0;
            $monthSandwichDays = 0;
            $WORK_HOURS_PER_DAY = 8.5;
            $totalActualLeaveHours = 0;
            // ✅ 1. Calculate ACTUAL leave days (Full/Half/Short)
            foreach ($allApprovedLeaves as $leave) {
                $leaveStart = Carbon::parse($leave->start_date);
                $leaveEnd = Carbon::parse($leave->end_date);

                // ✅ Clip to month boundaries
                $monthLeaveStart = $leaveStart->copy()->max($startDate);
                $monthLeaveEnd = $leaveEnd->copy()->min($endDate);

                if ($monthLeaveStart <= $monthLeaveEnd) {
                    if (empty($leave->halfday_period) && empty($leave->hours)) {
                        // FULL DAY
                        $fullDays = (int) floor($monthLeaveStart->diffInDays($monthLeaveEnd, false) + 1);
                        $monthLeaveDays += $fullDays;
                        $totalActualLeaveHours += $fullDays * $WORK_HOURS_PER_DAY;
                    } elseif (!empty($leave->halfday_period)) {
                        // HALF DAY
                        $halfDays = (int) floor($monthLeaveStart->diffInDays($monthLeaveEnd, false) + 1);
                        $monthLeaveDays += $halfDays * 0.5;
                        $totalActualLeaveHours += $halfDays * ($WORK_HOURS_PER_DAY / 2);
                    } elseif (!empty($leave->hours)) {
                        // ✅ SHORT DAY: Parse time range
                        $timeRange = $leave->hours;
                        if (preg_match('/(\d{1,2}:\d{2}\s*(?:AM|PM))(?:\s*to\s*)(.+)/i', $timeRange, $matches)) {
                            $startTime = $matches[1];
                            $endTime = trim($matches[2]);
                            $start = Carbon::parse($startTime);
                            $end = Carbon::parse($endTime);
                            $leaveHours = $start->floatDiffInHours($end);
                        } else {
                            $leaveHours = (float) $leave->hours;
                        }

                        $dayFraction = $leaveHours / $WORK_HOURS_PER_DAY;
                        $monthLeaveDays += $dayFraction;
                        $totalActualLeaveHours += $leaveHours;
                    }
                }
            }

            // ✅ 2. DYNAMIC sandwich days (only full days qualify)
            foreach ($allApprovedLeaves as $leave) {
                // Skip half/short leaves for sandwich
                if (!empty($leave->halfday_period) || !empty($leave->hours)) {
                    continue;
                }

                $leaveStart = Carbon::parse($leave->start_date);
                $leaveEnd = Carbon::parse($leave->end_date);

                // Day BEFORE leave starts
                $sandwichBefore = $leaveStart->copy()->subDay();
                if ($sandwichBefore >= $startDate && $sandwichBefore <= $endDate) {
                    if ($calendarController->isNonWorkingDay($sandwichBefore->toDateString())) {
                        $monthSandwichDays += 1;
                    }
                }

                // Day AFTER leave ends
                $sandwichAfter = $leaveEnd->copy()->addDay();
                if ($sandwichAfter >= $startDate && $sandwichAfter <= $endDate) {
                    if ($calendarController->isNonWorkingDay($sandwichAfter->toDateString())) {
                        $monthSandwichDays += 1;
                    }
                }
            }

            // ✅ Hours calculation
            $approvedLeaveDays = round($monthLeaveDays, 2);
            $approvedLeaveHours = round($monthLeaveDays * $WORK_HOURS_PER_DAY, 2);
            $sandwichHours = round($monthSandwichDays * $WORK_HOURS_PER_DAY, 2);
            $deductedDays = round($monthLeaveDays + $monthSandwichDays, 2);
            $totalDeductedHours = round($approvedLeaveHours + $sandwichHours, 2);

            $credit->leave_days = $approvedLeaveDays;
            $credit->deducted_days = $deductedDays;
            $credit->leave_taken_hours = $approvedLeaveHours;
            $credit->total_deducted_hours = $totalDeductedHours;

            // ... rest of your existing code unchanged (provisional, holidays, paid/unpaid, etc.)
            // Provisional calculations
            $provisionalLeaves = $credit->user->leaves()
                ->where('status', 'Approved')
                ->where('employment_period', 'provisional')
                ->get();
            $credit->provisional_leave_taken = round((float) $provisionalLeaves->sum('deducted_days'), 2);
            $credit->provisional_extended_months = 0;

            if ($credit->employment_status === 'provisional') {
                $credit->user->setRelation('leaves', $provisionalLeaves);
            } else {
                $credit->user->setRelation('leaves', $allApprovedLeaves);
            }

            $limit = $credit->provisional_leave_limit ?? 3;
            if ($credit->provisional_leave_taken > $limit) {
                $credit->provisional_extended_months = round($credit->provisional_leave_taken - $limit, 2);
            }

            $monthlyLimitHours = round((float) ($credit->paid_leaves ?? 0) * $WORK_HOURS_PER_DAY, 2);
            $totalAllowedHours = round((($credit->paid_leaves ?? 0) + ($credit->carry_forward_balance ?? 0)) * $WORK_HOURS_PER_DAY, 2);
            $credit->remaining_paid_leave_hours = round(max(0, $totalAllowedHours - $approvedLeaveHours), 2);

            // Holidays (your existing code)
            $holidayOverrides = DayOverride::where('is_working', 0)
                ->whereBetween('date', [$startDate, $endDate])
                ->pluck('date');

            $totalHolidayDays = 0;
            foreach ($holidayOverrides as $dateStr) {
                $date = Carbon::parse($dateStr);
                $weekly = \App\Models\WeeklyWorkingDay::where('day_of_week', $date->dayOfWeek)->first();
                if ($weekly?->is_working) {
                    $totalHolidayDays++;
                }
            }

            $totalHolidayHours = round($totalHolidayDays * $WORK_HOURS_PER_DAY, 2);
            $credit->holiday_days = $totalHolidayDays;
            $credit->holiday_hours = $totalHolidayHours;

            $dateForMonth = Carbon::create($year, $month, 1);
            $expectedWorkingDays = LeaveCreditService::getWorkingDaysInMonth($dateForMonth);
            $expectedWorkingHours = round($expectedWorkingDays * $WORK_HOURS_PER_DAY, 2);

            $credit->expected_working_hours = $expectedWorkingHours;
            $credit->worked_hours = round(max(0, $expectedWorkingHours - $approvedLeaveHours), 2);
            $credit->expected_working_days = $expectedWorkingDays;

            // Paid/Unpaid (your existing code)
            if ($expectedWorkingDays < 20) {
                $credit->paid_hours = 0;
                $credit->unpaid_hours = $totalDeductedHours;
            } else {
                if ($approvedLeaveHours <= $monthlyLimitHours) {
                    $credit->paid_hours = $approvedLeaveHours;
                    $credit->unpaid_hours = 0;
                } else {
                    $carryHours = round((float) ($credit->carry_forward_balance ?? 0) * $WORK_HOURS_PER_DAY, 2);
                    $unpaidHours = max(0, $totalDeductedHours - $monthlyLimitHours - $carryHours);
                    $credit->paid_hours = round($totalDeductedHours - $unpaidHours, 2);
                    $credit->unpaid_hours = round($unpaidHours, 2);
                }
            }

            // Notice period & provisional end date (your existing code)
            if ($credit->employment_status === 'notice' && $credit->notice_start_date) {
                $noticeStart = Carbon::parse($credit->notice_start_date);
                $basePeriodDays = (int) ($credit->notice_period_days ?? 0);
                $initialEndDate = $noticeStart->copy()->addDays($basePeriodDays);
                $noticeLeaves = $credit->user->leaves()
                    ->where('status', 'Approved')
                    ->whereBetween('start_date', [$noticeStart->toDateString(), $initialEndDate->toDateString()])
                    ->get();
                $totalDeductedDays = (float) $noticeLeaves->sum('deducted_days');
                $extensionDays = (int) floor($totalDeductedDays);
                $finalNoticePeriod = $basePeriodDays + $extensionDays;
                $credit->notice_end_date = $noticeStart->copy()->addDays($finalNoticePeriod)->toDateString();
            } else {
                $credit->notice_end_date = null;
            }

            $credit->provisional_end_date = $credit->joining_date
                ? Carbon::parse($credit->joining_date)
                ->addDays($credit->provisional_days ?? 0)
                ->addDays((int) round(30 * ($credit->provisional_extended_months ?? 0)))
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
        /**working hours used here */
        $WORKING_HOURS = 8.5;

        return $leaves->sum(function ($leave) use ($WORKING_HOURS) {

            // If total_hours already calculated
            if (!empty($leave->total_hours)) {
                return (float) $leave->total_hours;
            }

            $hours = 0;

            // Actual leave hours
            if ($leave->leave_type === 'Full Leave') {
                $hours += $WORKING_HOURS;
            }

            if ($leave->leave_type === 'Half Day') {
                $hours += $WORKING_HOURS / 2;
            }

            if ($leave->leave_type === 'Short Leave' && $leave->start_time && $leave->end_time) {
                $start = Carbon::parse($leave->start_time);
                $end   = Carbon::parse($leave->end_time);
                $hours += $end->diffInMinutes($start) / 60;
            }

            // Add sandwich days hours
            // if (!empty($leave->sandwich_extra_days)) {
            //     $hours += $leave->sandwich_extra_days * $WORKING_HOURS;
            // }

            return $hours;
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
        $leaveCredits =  LeaveCredit::with([
            'user:id,name',
            'user.leaves'
        ])->find($id);
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
            $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
            $monthEnd   = Carbon::create($year, $month, 1)->endOfMonth();

            /*HR Non-Working Days (Holiday*/
            $holidayOverrides = DayOverride::where('is_working', 0)
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->get();

            $totalHolidayDays = 0;

            foreach ($holidayOverrides as $override) {

                $date = Carbon::parse($override->date);

                $weekly = \App\Models\WeeklyWorkingDay::where(
                    'day_of_week',
                    $date->dayOfWeek
                )->first();

                // count only if weekly rule says working
                if ($weekly && $weekly->is_working == 1) {
                    $totalHolidayDays++;
                }
            }
            $totalHolidayHours = $totalHolidayDays * 8.5;

            $credit->holiday_days = $totalHolidayDays;
            $credit->holiday_hours = $totalHolidayHours;

            $expectedWorkingDays =
                LeaveCreditService::getWorkingDaysInMonth($dateForMonth);

            /**working hours */
            $expectedWorkingHours = ($expectedWorkingDays * 8.5);
            $credit->expected_working_hours = max(0, $expectedWorkingHours);
            $credit->worked_hours = max(0, $expectedWorkingHours - $approvedLeaveHours);
            $credit->expected_working_days = $expectedWorkingDays;

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
            'message' => "Data Updated for Current Month",
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
