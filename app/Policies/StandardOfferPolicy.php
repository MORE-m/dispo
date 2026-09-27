<?php

namespace App\Policies;

use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;

/**
 * AUTH-006, AUTH-007, STD-003, STD-004, STD-009.
 */
class StandardOfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewStandardOffers();
    }

    public function view(User $user, StandardOffer $offer): bool
    {
        if (! $user->canViewStandardOffers()) {
            return false;
        }

        if ($user->canManageStandardOffers()) {
            return true;
        }

        return $offer->publishedVersion()->exists();
    }

    public function create(User $user): bool
    {
        return $user->canManageStandardOffers();
    }

    public function update(User $user, StandardOffer $offer): bool
    {
        return $user->canManageStandardOffers();
    }

    public function publish(User $user, StandardOfferVersion $version): bool
    {
        return $user->canManageStandardOffers();
    }

    public function archive(User $user, StandardOfferVersion $version): bool
    {
        return $user->canManageStandardOffers();
    }

    public function adopt(User $user, StandardOfferVersion $version): bool
    {
        return $user->canAdoptStandardOffers() && $version->status->isAdoptable();
    }
}
