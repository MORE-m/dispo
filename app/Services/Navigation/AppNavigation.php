<?php

namespace App\Services\Navigation;

use App\Models\User;

final class AppNavigation
{
    /**
     * @return list<array{key: string, title: string, href: string, available: bool}>
     */
    public function itemsFor(User $user): array
    {
        $items = [
            [
                'key' => 'overview',
                'title' => 'Übersicht',
                'href' => '/dashboard',
                'available' => true,
                'visible' => true,
            ],
            [
                'key' => 'calculations',
                'title' => 'Kalkulationen',
                'href' => '/kalkulationen',
                'available' => true,
                'visible' => $user->canAccessCalculations(),
            ],
            [
                'key' => 'standard-offers',
                'title' => 'Standardangebote',
                'href' => '/standardangebote',
                'available' => false,
                'visible' => $user->canViewStandardOffers(),
            ],
            [
                'key' => 'dispo-orders',
                'title' => 'Dispoaufträge',
                'href' => '/dispoauftraege',
                'available' => true,
                'visible' => $user->canViewDispoOrders(),
            ],
            [
                'key' => 'reports',
                'title' => 'Auswertungen',
                'href' => '/auswertungen',
                'available' => false,
                'visible' => $user->canViewReports(),
            ],
            [
                'key' => 'master-data',
                'title' => 'Stammdaten',
                'href' => '/stammdaten',
                'available' => false,
                'visible' => $user->canViewMasterData(),
            ],
            [
                'key' => 'administration',
                'title' => 'Administration',
                'href' => '/administration',
                'available' => false,
                'visible' => $user->canAccessAdministration(),
            ],
        ];

        return array_values(array_filter(
            $items,
            fn (array $item): bool => $item['visible'],
        ));
    }
}
