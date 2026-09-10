<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADV-001b: Katalog-Hub (Oberkategorien / Werbemittel).
 */
class CatalogHubController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/katalog/index', [
            'links' => [
                [
                    'title' => 'Oberkategorien',
                    'description' => 'Technische Keys, Namen, Sortierung und Aktivstatus pflegen.',
                    'href' => '/administration/katalog/oberkategorien',
                ],
                [
                    'title' => 'Werbemittel',
                    'description' => 'Codes, fachliche Eigenschaften und Zuordnung zur Oberkategorie.',
                    'href' => '/administration/katalog/werbemittel',
                ],
                [
                    'title' => 'Berechnungsmethoden',
                    'description' => 'Systemdefinierte Methodenstammdaten, Registry-Status und Lifecycle.',
                    'href' => '/administration/katalog/berechnungsmethoden',
                ],
            ],
            'boundaryNote' => 'Dynamische Felder definieren Eingabefelder und Regeln. Der Katalog verwaltet fachliche Werbemittel, Oberkategorien und Berechnungsmethoden.',
        ]);
    }
}
