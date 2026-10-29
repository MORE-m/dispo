<?php

namespace App\Http\Controllers\Administration\OnlineAudio;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PO-CALC-TKP-1D4c-4d: Einstieg Online-Audio-Admin (nur manage-oa-tkp-master-data).
 */
class OaAdminHubController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('manage-oa-tkp-master-data');

        return Inertia::render('administration/online-audio/index', [
            'sections' => [
                [
                    'key' => 'price-lists',
                    'title' => 'TKP-Preislisten',
                    'description' => 'Jahresrevisionen, Preismatrix, Prüfung und Aktivierung.',
                    'href' => route('administration.oa-tkp.price-lists.index'),
                ],
                [
                    'key' => 'targetings',
                    'title' => 'Targetings',
                    'description' => 'Listen- und Mindest-Aufschläge revisionssicher pflegen.',
                    'href' => route('administration.oa-tkp.targetings.index'),
                ],
                [
                    'key' => 'platforms',
                    'title' => 'Plattformen',
                    'description' => 'Plattformen anlegen, bearbeiten und aktivieren/deaktivieren.',
                    'href' => route('administration.oa-tkp.platforms.index'),
                ],
            ],
        ]);
    }
}
