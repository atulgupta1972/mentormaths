<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use App\Services\UserGroupService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        $userGroupService = app(UserGroupService::class);

        foreach ([
            ['code' => User::ROLE_ADMIN, 'name' => 'Admin', 'sort_order' => 1],
            ['code' => User::ROLE_TEACHER, 'name' => 'Teacher', 'sort_order' => 2],
            ['code' => User::ROLE_STUDENT, 'name' => 'Student', 'sort_order' => 3],
            ['code' => User::ROLE_PARENT, 'name' => 'Parent', 'sort_order' => 4],
        ] as $group) {
            Group::query()->updateOrCreate(
                ['code' => $group['code']],
                [
                    'name' => $group['name'],
                    'sort_order' => $group['sort_order'],
                    'is_active' => true,
                ],
            );
        }

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@mathsfoundation.in'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $userGroupService->attachGroupByCode($admin, User::ROLE_ADMIN);

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

        Subject::query()->updateOrCreate(
            ['code' => 'MATHS'],
            ['name' => 'Mathematics', 'is_active' => true],
        );
    }
}
