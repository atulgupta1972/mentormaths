<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            ['code' => User::ROLE_ADMIN, 'name' => 'Admin', 'sort_order' => 1],
            ['code' => User::ROLE_TEACHER, 'name' => 'Teacher', 'sort_order' => 2],
            ['code' => User::ROLE_STUDENT, 'name' => 'Student', 'sort_order' => 3],
            ['code' => User::ROLE_PARENT, 'name' => 'Parent', 'sort_order' => 4],
        ];

        foreach ($defaults as $group) {
            Group::query()->updateOrCreate(
                ['code' => $group['code']],
                [
                    'name' => $group['name'],
                    'sort_order' => $group['sort_order'],
                    'is_active' => true,
                ],
            );
        }

        $groupsByCode = Group::query()->pluck('id', 'code');

        User::query()->each(function (User $user) use ($groupsByCode) {
            $code = $user->role;

            if (! $groupsByCode->has($code)) {
                return;
            }

            DB::table('group_user')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'group_id' => $groupsByCode[$code],
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    public function down(): void
    {
        DB::table('group_user')->truncate();
        Group::query()->whereIn('code', [
            User::ROLE_ADMIN,
            User::ROLE_TEACHER,
            User::ROLE_STUDENT,
            User::ROLE_PARENT,
        ])->delete();
    }
};
