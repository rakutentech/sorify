<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $created = User::firstOrCreate(
            ['email' => 'admin@sorify.local'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('changeme'),
                'is_admin' => true,
            ]
        );

        if ($created->wasRecentlyCreated) {
            $this->command->warn('Default admin created: admin@sorify.local / changeme — change this password immediately.');
        }
    }
}
