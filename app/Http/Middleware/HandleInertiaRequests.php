<?php

namespace App\Http\Middleware;

use App\Services\Navigation\AppNavigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role->value,
                    'role_label' => $request->user()->role->label(),
                ] : null,
            ],
            'navigation' => $request->user()
                ? app(AppNavigation::class)->itemsFor($request->user())
                : [],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'sidebarOpen' => $request->hasCookie('sidebar_state')
                ? $request->cookie('sidebar_state') === 'true'
                : null,
            // Nur für isolierte Playwright-Server (E2E_SERVER=1); nie in Produktion.
            'e2eServer' => (bool) config('app.e2e_server'),
        ];
    }
}
