<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Per-instance caches for the authorisation lookups the layout repeats on
     * every request. Not persisted, and cleared by forgetPermissionCache().
     *
     * @var Collection<int, string>|null
     */
    protected ?Collection $memoizedPermissionNames = null;

    /** @var Collection<int, string>|null */
    protected ?Collection $memoizedRoleNames = null;

    /** @var array<int, int>|null */
    protected ?array $memoizedAccessibleProjectIds = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_secret' => 'encrypted',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('project_id')
            ->withTimestamps();
    }

    public function ownedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_owners')
            ->withPivot('share_bps')
            ->withTimestamps();
    }

    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_user')
            ->withPivot('assignment_note')
            ->withTimestamps();
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    public function payRates(): HasMany
    {
        return $this->hasMany(PayRate::class);
    }

    public function partnerProfile(): HasOne
    {
        return $this->hasOne(PartnerProfile::class);
    }

    public function partnerLedgerEntries(): HasMany
    {
        return $this->hasMany(PartnerLedgerEntry::class);
    }

    /**
     * Portfolio-wide finance (admin or accountant money roles).
     * Enables all-project scope for revenue/expense/PnL queries.
     */
    public function hasPortfolioFinanceAccess(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyPermission(
            'revenue.manage',
            'expenses.manage',
            'distributions.manage',
            'distributions.approve',
        );
    }

    /**
     * Effective permissions = union of all assigned role permissions.
     *
     * Memoized for the life of the instance. The navigation alone asks about
     * twenty permissions, and the layout builds the sidebar, the mobile bar and
     * the command palette from it, so an uncached lookup meant dozens of
     * identical role queries before any page did its own work.
     */
    public function permissionNames(): Collection
    {
        return $this->memoizedPermissionNames ??= $this->roles()
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }

    /**
     * Drop the memoized lookups after a role change within the same request.
     */
    public function forgetPermissionCache(): void
    {
        $this->memoizedPermissionNames = null;
        $this->memoizedRoleNames = null;
        $this->memoizedAccessibleProjectIds = null;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissionNames()->contains($permission);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        $owned = $this->permissionNames();

        foreach ($permissions as $permission) {
            if ($owned->contains($permission)) {
                return true;
            }
        }

        return false;
    }

    public function assignRole(string|Role $role, ?int $projectId = null): void
    {
        $roleId = $role instanceof Role
            ? $role->id
            : Role::query()->where('name', $role)->firstOrFail()->id;

        $this->roles()->syncWithoutDetaching([
            $roleId => ['project_id' => $projectId],
        ]);

        $this->forgetPermissionCache();
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roleNames()->contains($roleName);
    }

    /**
     * @return Collection<int, string>
     */
    public function roleNames(): Collection
    {
        return $this->memoizedRoleNames ??= $this->roles()->pluck('name');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Project IDs this user may access.
     * Admin: all. Otherwise: team (project_user) ∪ supervisor role_user.project_id ∪ ownership.
     *
     * @return array<int, int>
     */
    public function accessibleProjectIds(): array
    {
        if ($this->memoizedAccessibleProjectIds !== null) {
            return $this->memoizedAccessibleProjectIds;
        }

        if ($this->isAdmin() || $this->hasPortfolioFinanceAccess()) {
            return $this->memoizedAccessibleProjectIds = Project::query()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $teamIds = DB::table('project_user')
            ->where('user_id', $this->id)
            ->pluck('project_id');

        $roleScopedIds = DB::table('role_user')
            ->where('user_id', $this->id)
            ->whereNotNull('project_id')
            ->pluck('project_id');

        $ownedIds = DB::table('project_owners')
            ->where('user_id', $this->id)
            ->pluck('project_id');

        return $this->memoizedAccessibleProjectIds = $teamIds
            ->merge($roleScopedIds)
            ->merge($ownedIds)
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function canAccessProject(Project $project): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array((int) $project->id, $this->accessibleProjectIds(), true);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret) && filled($this->two_factor_confirmed_at);
    }
}
