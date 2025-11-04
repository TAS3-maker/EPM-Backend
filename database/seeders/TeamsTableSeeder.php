<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TeamsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teams = [
            'Frontend development',
            'Backend development',
            'SEO',
            'Business Development',
        ];

        foreach ($teams as $team) {
            Team::firstOrCreate(['name' => $team]);
        }
    }
}
