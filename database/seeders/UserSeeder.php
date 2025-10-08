<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
         $superAdminRole = Role::where('name', 'Super Admin')->first();

        if ($superAdminRole) {
            User::updateOrCreate(
                ['email' => 'admin@gmail.com'],
                [
                    'name' => 'Super Admin',
                    'email' => 'admin@gmail.com',
                    'password' => Hash::make('admin@123'),
                    'role_id' => $superAdminRole->id,
                ]
            );
        }
    }
}
