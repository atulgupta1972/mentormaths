<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Services\UserGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private UserGroupService $userGroupService,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->toString();
        $groupId = $request->integer('group_id');

        $users = User::query()
            ->with('groups:id,code,name')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($groupId > 0, fn ($query) => $query->whereHas(
                'groups',
                fn ($q) => $q->where('groups.id', $groupId),
            ))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'groups' => Group::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'is_active']),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'group_id' => $groupId > 0 ? $groupId : '',
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Create', [
            'groups' => $this->assignableGroups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:15'],
            'password' => ['required', Password::defaults()],
            'is_active' => ['boolean'],
            'group_ids' => ['required', 'array', 'min:1'],
            'group_ids.*' => ['integer', Rule::exists('groups', 'id')->where('is_active', true)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'] ?? null,
            'password' => Hash::make($validated['password']),
            'is_active' => $validated['is_active'] ?? true,
            'email_verified_at' => now(),
            'role' => User::ROLE_STUDENT,
        ]);

        $this->userGroupService->syncGroups($user, $validated['group_ids']);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User created successfully.');
    }

    public function edit(User $user): Response
    {
        $user->load('groups:id');

        return Inertia::render('Admin/Users/Edit', [
            'user' => $user->only(['id', 'name', 'email', 'mobile', 'is_active', 'role']),
            'selectedGroupIds' => $user->groups->pluck('id'),
            'groups' => $this->assignableGroups(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile' => ['nullable', 'string', 'max:15'],
            'password' => ['nullable', Password::defaults()],
            'is_active' => ['boolean'],
            'group_ids' => ['required', 'array', 'min:1'],
            'group_ids.*' => ['integer', Rule::exists('groups', 'id')->where('is_active', true)],
        ]);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'] ?? null,
            'is_active' => $validated['is_active'] ?? false,
        ]);

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        $this->userGroupService->syncGroups($user, $validated['group_ids']);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User updated successfully.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        $label = $user->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "User {$label} successfully.");
    }

    private function assignableGroups()
    {
        return Group::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
