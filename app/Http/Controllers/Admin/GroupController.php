<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    public function index(): Response
    {
        $groups = Group::query()
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Groups/Index', [
            'groups' => $groups,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:groups,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        Group::create([
            'code' => strtolower($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 99,
            'is_active' => true,
        ]);

        return back()->with('success', 'Group created successfully.');
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        $group->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? $group->sort_order,
            'is_active' => $validated['is_active'] ?? false,
        ]);

        return back()->with('success', 'Group updated successfully.');
    }

    public function destroy(Group $group): RedirectResponse
    {
        if ($group->users()->exists()) {
            return back()->with('error', 'Cannot delete a group that has users. Deactivate it instead.');
        }

        if (in_array($group->code, ['admin', 'teacher', 'student', 'parent'], true)) {
            return back()->with('error', 'Default system groups cannot be deleted.');
        }

        $group->delete();

        return back()->with('success', 'Group deleted successfully.');
    }
}
