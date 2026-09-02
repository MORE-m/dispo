<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Role $role
 * @property string|null $discount_limit_percent
 * @property bool $can_special_approve
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'discount_limit_percent', 'can_special_approve'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'discount_limit_percent' => 'decimal:4',
            'can_special_approve' => 'boolean',
        ];
    }

    public function hasRole(Role $role): bool
    {
        return $this->role === $role;
    }

    public function hasAnyRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function canAccessCalculations(): bool
    {
        return $this->hasAnyRole(
            Role::Admin,
            Role::Sales,
            Role::Disposition,
            Role::Management,
        );
    }

    public function canManageCalculations(): bool
    {
        return $this->hasAnyRole(Role::Admin, Role::Sales, Role::Management);
    }

    public function canViewStandardOffers(): bool
    {
        return $this->hasAnyRole(
            Role::Admin,
            Role::Sales,
            Role::Management,
            Role::ProductManagement,
        );
    }

    public function canViewDispoOrders(): bool
    {
        return $this->hasAnyRole(
            Role::Admin,
            Role::Sales,
            Role::Disposition,
            Role::Management,
        );
    }

    public function canManageDispoOrders(): bool
    {
        return $this->hasAnyRole(Role::Admin, Role::Sales, Role::Management);
    }

    public function canViewReports(): bool
    {
        return $this->hasAnyRole(
            Role::Admin,
            Role::Sales,
            Role::Disposition,
            Role::Management,
        );
    }

    public function canViewMasterData(): bool
    {
        return $this->hasAnyRole(Role::Admin, Role::Management, Role::ProductManagement);
    }

    public function canAccessAdministration(): bool
    {
        return $this->hasAnyRole(Role::Admin, Role::Management);
    }
}
