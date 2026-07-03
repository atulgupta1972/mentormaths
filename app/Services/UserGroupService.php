<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

class UserGroupService
{
    /** @var list<string> */
    private const ROLE_PRIORITY = [
        User::ROLE_ADMIN,
        User::ROLE_TEACHER,
        User::ROLE_PARENT,
        User::ROLE_STUDENT,
    ];

    public function syncGroups(User $user, array $groupIds): void
    {
        $activeGroupIds = Group::query()
            ->whereIn('id', $groupIds)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $user->groups()->sync($activeGroupIds);
        $this->syncPrimaryRole($user);
    }

    public function attachGroupByCode(User $user, string $code): void
    {
        $group = Group::query()->where('code', $code)->where('is_active', true)->first();

        if ($group) {
            $user->groups()->syncWithoutDetaching([$group->id]);
            $this->syncPrimaryRole($user);
        }
    }

    public function syncPrimaryRole(User $user): void
    {
        $user->load('groups:id,code');

        $primaryRole = $this->resolvePrimaryRole($user->groups);

        if ($primaryRole !== null && $user->role !== $primaryRole) {
            $user->forceFill(['role' => $primaryRole])->save();
        }
    }

    public function resolvePrimaryRole(Collection $groups): ?string
    {
        $codes = $groups->pluck('code');

        foreach (self::ROLE_PRIORITY as $role) {
            if ($codes->contains($role)) {
                return $role;
            }
        }

        $first = $groups->sortBy('sort_order')->first();

        return $first?->code;
    }
}
