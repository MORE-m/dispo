<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DF-3.1 / UX-GATE-D Teilfreigabe: Admin-Hub; nur Dyn-Felder freigeschaltet.
 */
class AdministrationHubController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/index', [
            'modules' => [
                [
                    'key' => 'dynamic-fields',
                    'title' => 'Dynamische Felder',
                    'description' => 'Systemfeld-Revisionen und Kern-Feldset-Versionen (DF-3.1).',
                    'href' => '/administration/dynamische-felder',
                    'available' => true,
                ],
                [
                    'key' => 'inventories',
                    'title' => 'Inventare / Kombis',
                    'description' => 'Noch nicht freigegeben (UX-GATE-D).',
                    'href' => null,
                    'available' => false,
                ],
                [
                    'key' => 'catalog',
                    'title' => 'Werbemittel / Kategorien',
                    'description' => 'Noch nicht freigegeben (UX-GATE-D).',
                    'href' => null,
                    'available' => false,
                ],
                [
                    'key' => 'price-lists',
                    'title' => 'Preislisten',
                    'description' => 'Noch nicht freigegeben (UX-GATE-D).',
                    'href' => null,
                    'available' => false,
                ],
            ],
        ]);
    }
}
