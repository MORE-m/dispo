<?php

namespace App\Support\Organization;

use App\Models\Organization;

/**
 * V1: genau eine Organisation. Keine Auswahl, keine Mehrmandantenfähigkeit.
 * Vorhandene Datensätze werden nicht umbenannt.
 */
final class SingletonOrganizationResolver
{
    public function resolve(): Organization
    {
        $existing = Organization::query()->orderBy('id')->first();
        if ($existing !== null) {
            return $existing;
        }

        $organization = new Organization;
        $organization->name = 'more Marketing';
        $organization->save();

        return $organization;
    }
}
