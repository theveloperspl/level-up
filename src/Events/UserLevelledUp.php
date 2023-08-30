<?php

namespace LevelUp\Experience\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class UserLevelledUp
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public int $level
    ) {
    }
}
