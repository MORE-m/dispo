<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UX-GATE-D Teilfreigabe: Dyn-Felder + Katalog (ADV-001b) + Inventar-Admin (BL-P2-01a).
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
                    'description' => 'Inventare anlegen, bearbeiten und aktivieren. Kombi-Mitgliedschaften folgen später.',
                    'href' => '/administration/inventare',
                    'available' => true,
                ],
                [
                    'key' => 'catalog',
                    'title' => 'Werbemittel / Kategorien',
                    'description' => 'Oberkategorien und Werbemittel verwalten (ADV-001b / UX-GATE-D Teilfreigabe).',
                    'href' => '/administration/katalog',
                    'available' => true,
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
