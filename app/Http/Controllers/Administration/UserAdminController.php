<?php

namespace App\Http\Controllers\Administration;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\StoreUserAdminRequest;
use App\Http\Requests\Administration\UpdateUserAdminRequest;
use App\Models\User;
use App\Services\User\Admin\UserAdminWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PO-USER-ADMIN-1: minimale Benutzerverwaltung (Liste/Anlegen/Bearbeiten).
 * Kein Hard Delete – nur is_active.
 */
class UserAdminController extends Controller
{
    public function __construct(
        private readonly UserAdminWriter $writer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-users');

        $users = User::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'email',
                'role',
                'discount_limit_percent',
                'can_special_approve',
                'is_active',
            ]);

        return Inertia::render('administration/users/index', [
            'users' => $users->map(fn (User $user): array => $this->serializeListRow($user))->values()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('manage-users');

        return Inertia::render('administration/users/create', [
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function store(StoreUserAdminRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $user = $this->writer->create($request->adminPayload(), $actor);

        return redirect()
            ->route('administration.users.edit', $user)
            ->with('success', 'Benutzer angelegt.');
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('manage-users');

        return Inertia::render('administration/users/edit', [
            'user' => $this->serializeEdit($user),
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function update(UpdateUserAdminRequest $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->writer->update($user, $request->adminPayload(), $actor);

        return redirect()
            ->route('administration.users.edit', $user)
            ->with('success', 'Benutzer gespeichert.');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return array_map(
            fn (Role $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ],
            Role::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'discount_limit_percent' => $user->discount_limit_percent,
            'can_special_approve' => (bool) $user->can_special_approve,
            'is_active' => (bool) $user->is_active,
            'status_label' => $user->is_active ? 'Aktiv' : 'Inaktiv',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEdit(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'discount_limit_percent' => $user->discount_limit_percent,
            'can_special_approve' => (bool) $user->can_special_approve,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
