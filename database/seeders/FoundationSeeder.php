<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@mathsfoundation.in'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'email_verified_at' => now(),
            ],
        );

        AcademicYear::query()->updateOrCreate(
            ['name' => '2026-27'],
            [
                'starts_on' => '2026-03-01',
                'ends_on' => '2027-02-28',
                'is_active' => true,
                'notes' => 'Academic year Mar 2026 – Feb 2027',
            ],
        );

        foreach ([
            ['code' => 'CBSE', 'name' => 'Central Board of Secondary Education'],
            ['code' => 'ICSE', 'name' => 'Indian Certificate of Secondary Education'],
        ] as $board) {
            Board::query()->updateOrCreate(
                ['code' => $board['code']],
                ['name' => $board['name'], 'is_active' => true],
            );
        }

        foreach (range(1, 12) as $class) {
            GradeLevel::query()->updateOrCreate(
                ['name' => "Class {$class}"],
                ['sort_order' => $class, 'is_active' => true],
            );
        }

        \App\Models\Subject::query()->updateOrCreate(
            ['code' => 'MATHS'],
            ['name' => 'Mathematics', 'is_active' => true],
        );
    }
}
