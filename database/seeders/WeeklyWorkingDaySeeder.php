<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WeeklyWorkingDaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $days = [
            ['day_of_week' => 0, 'is_working' => 0],
            ['day_of_week' => 1, 'is_working' => 1],
            ['day_of_week' => 2, 'is_working' => 1],
            ['day_of_week' => 3, 'is_working' => 1],
            ['day_of_week' => 4, 'is_working' => 1],
            ['day_of_week' => 5, 'is_working' => 1],
            ['day_of_week' => 6, 'is_working' => 0],
        ];

        foreach ($days as $day) {
            DB::table('weekly_working_days')->insert($day);
        }
    }
}
