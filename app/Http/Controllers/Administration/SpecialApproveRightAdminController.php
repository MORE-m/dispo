<?php

namespace App\Http\Controllers\Administration;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateSpecialApproveRightRequest;
use App\Models\User;
use App\Services\User\Admin\SpecialApproveRightAdminWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PO-AUTH-SPECIAL-APPROVE-1: schmale Admin-Oberfläche nur für Sonderfreigaberecht.
 */
class SpecialApproveRightAdminController extends Controller
{
    public function __construct(
        private readonly SpecialApproveRightAdminWriter $writer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-special-approve-rights');

        $salesUsers = User::query()
            ->where('role', Role::Sales)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'can_special_approve']);

        return Inertia::render('administration/special-approve-rights/index', [
            'salesUsers' => $salesUsers->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'can_special_approve' => (bool) $user->can_special_approve,
            ])->values()->all(),
        ]);
    }

    public function update(UpdateSpecialApproveRightRequest $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->writer->update($user, $request->canSpecialApprove(), $actor);

        $message = $request->canSpecialApprove()
            ? 'Sonderfreigaberecht vergeben.'
            : 'Sonderfreigaberecht entzogen.';

        return redirect()
            ->route('administration.special-approve-rights.index')
            ->with('success', $message);
    }
}
