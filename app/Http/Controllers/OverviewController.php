<?php

namespace App\Http\Controllers;

use App\Models\Calculation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $recent = [];

        if ($user?->can('viewAny', Calculation::class)) {
            $recent = Calculation::query()
                ->latest()
                ->limit(8)
                ->get(['id', 'number', 'campaign', 'nn_invest', 'updated_at']);
        }

        return Inertia::render('overview', [
            'recentCalculations' => $recent,
            'canCreateCalculation' => $user?->can('create', Calculation::class) ?? false,
        ]);
    }
}
