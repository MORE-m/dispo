<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UX-GATE-D Teilfreigabe: Dyn-Felder + Katalog (ADV-001b) + Inventar-Admin (BL-P2-01a)
 * + Preislisten-Admin-Lifecycle (BL-P4-01a, ohne Excel-Import).
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
                    'description' => 'Inventare und Kombis anlegen, bearbeiten und aktivieren.',
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
                    'description' => 'Jahresversionen anlegen, kopieren, bearbeiten und veröffentlichen (ohne Excel-Import).',
                    'href' => '/administration/preislisten',
                    'available' => true,
                ],
            ],
        ]);
    }
}
