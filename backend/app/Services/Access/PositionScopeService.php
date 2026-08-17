<?php

namespace App\Services\Access;

use App\Models\Application;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PositionScopeService
{
    /**
     * @return list<int>|null  null = unrestricted (all positions)
     */
    public function allowedPositionIds(?User $user = null): ?array
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        if ($user->hasRole('System Administrator')) {
            return null;
        }

        $ids = $user->positionScopes()->pluck('position_id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return null;
        }

        return $ids;
    }

    public function isRestricted(?User $user = null): bool
    {
        return $this->allowedPositionIds($user) !== null;
    }

    public function canAccessPosition(?int $positionId, ?User $user = null): bool
    {
        $allowed = $this->allowedPositionIds($user);
        if ($allowed === null) {
            return true;
        }
        if ($positionId === null) {
            return false;
        }

        return in_array($positionId, $allowed, true);
    }

    public function assertCanAccessPosition(?int $positionId, ?User $user = null): void
    {
        if (! $this->canAccessPosition($positionId, $user)) {
            throw new AccessDeniedHttpException('You do not have access to reports for this position.');
        }
    }

    public function assertCanAccessApplication(Application $application, ?User $user = null): void
    {
        $this->assertCanAccessPosition($application->position_id, $user);
    }

    public function assertCanAccessCategoryKey(string $categoryKey, ?User $user = null): void
    {
        if ($categoryKey === 'unassigned') {
            $this->assertCanAccessPosition(null, $user);

            return;
        }

        if (ctype_digit($categoryKey)) {
            $this->assertCanAccessPosition((int) $categoryKey, $user);

            return;
        }

        $position = Position::query()
            ->where('reference_code', strtoupper($categoryKey))
            ->first();

        if (! $position) {
            throw new AccessDeniedHttpException('Unknown report category.');
        }

        $this->assertCanAccessPosition((int) $position->id, $user);
    }

    /**
     * @param  Builder<Position>  $query
     * @return Builder<Position>
     */
    public function scopePositionsQuery(Builder $query, ?User $user = null): Builder
    {
        $allowed = $this->allowedPositionIds($user);
        if ($allowed === null) {
            return $query;
        }

        return $query->whereIn('id', $allowed);
    }

    /**
     * @param  Builder<Application>|Builder<\App\Models\Application>  $query
     */
    public function scopeApplicationsQuery(Builder $query, ?User $user = null): Builder
    {
        $allowed = $this->allowedPositionIds($user);
        if ($allowed === null) {
            return $query;
        }

        return $query->whereIn('position_id', $allowed);
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    public function filterCategories(array $categories, ?User $user = null): array
    {
        $allowed = $this->allowedPositionIds($user);
        if ($allowed === null) {
            return $categories;
        }

        return array_values(array_filter($categories, function (array $row) use ($allowed): bool {
            $positionId = $row['position_id'] ?? null;

            return $positionId !== null && in_array((int) $positionId, $allowed, true);
        }));
    }
}
