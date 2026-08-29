<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnavailableModuleController extends Controller
{
    public function __invoke(Request $request, string $module): Response
    {
        $catalog = [
            'standard-offers' => [
                'title' => 'Standardangebote',
                'gate' => 'UX-GATE-D',
                'permission' => $request->user()?->canViewStandardOffers() ?? false,
            ],
            'dispo-orders' => [
                'title' => 'Dispoaufträge',
                'gate' => 'UX-GATE-D',
                'permission' => $request->user()?->canViewDispoOrders() ?? false,
            ],
            'reports' => [
                'title' => 'Auswertungen',
                'gate' => 'UX-GATE-D',
                'permission' => $request->user()?->canViewReports() ?? false,
            ],
            'master-data' => [
                'title' => 'Stammdaten',
                'gate' => 'UX-GATE-D',
                'permission' => $request->user()?->canViewMasterData() ?? false,
            ],
            'administration' => [
                'title' => 'Administration',
                'gate' => 'UX-GATE-D',
                'permission' => $request->user()?->canAccessAdministration() ?? false,
            ],
        ];

        abort_unless(isset($catalog[$module]), 404);
        abort_unless($catalog[$module]['permission'], 403);

        return Inertia::render('modules/unavailable', [
            'title' => $catalog[$module]['title'],
            'gate' => $catalog[$module]['gate'],
        ]);
    }
}
