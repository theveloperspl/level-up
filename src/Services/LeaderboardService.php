<?php

namespace LevelUp\Experience\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LevelUp\Experience\Models\Experience;

class LeaderboardService
{
    private mixed $userModel;

    public function __construct()
    {
        $this->userModel = config(key: 'level-up.user.model');
    }

    public function generate(bool $paginate = false, int $limit = null): array|Collection|LengthAwarePaginator
    {
        return $this->userModel::query()
            ->with(relations: ['experience', 'level'])
            ->orderByDesc(
                column: Experience::select('experience_points')
                    ->whereColumn('user_id', 'users.id')
                    ->latest()
            )
            ->take($limit)
            ->when($paginate, fn (Builder $query) => $query->paginate(), fn (Builder $query) => $query->get());
    }
}
