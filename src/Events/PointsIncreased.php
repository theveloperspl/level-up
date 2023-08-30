<?php

namespace LevelUp\Experience\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class PointsIncreased
{
    use Dispatchable;

    public function __construct(
        public int $pointsAdded,
        public int $totalPoints,
        public string $type,
        public ?string $reason,
        public User $user,
    ) {
    }
}
