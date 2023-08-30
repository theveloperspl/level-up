<?php

namespace LevelUp\Experience\Listeners;

use LevelUp\Experience\Enums\AuditType;
use LevelUp\Experience\Events\PointsIncreased;
use LevelUp\Experience\Models\Level;

class PointsIncreasedListener
{
    public function __invoke(PointsIncreased $event): void
    {
        $user = $event->user;
        $poinsAdded = $event->pointsAdded;
        $currenUserExperience = $event->totalPoints;
        $currentUserLevel = $user->getCurrentLevel();

        //if there are no levels created add starting level to database
        if (Level::count() === 0) {
            Level::add([
                'level' => config(key: 'level-up.starting_level'),
                'next_level_experience' => null,
            ]);
        }

        //get users next level
        $nextLevel = Level::firstWhere(column: 'level', operator: $currentUserLevel + 1);
        //abort if there is no level user can be promoted to
        if (!$nextLevel) {
            return;
        }

        $nextLevelRequiredExperience = $nextLevel->next_level_experience;
        if ($currenUserExperience < $nextLevelRequiredExperience) {
            //store full points in history instead of breaking them up to chunks fitting levels requirements
            if (config(key: 'level-up.audit.enabled')) {
                $user->experienceHistory()->create([
                    'points' => $poinsAdded,
                    'type' => $event->type,
                    'reason' => $event->reason,
                ]);
            }

            return;
        } else {
            $remainingExperience = $currenUserExperience - $nextLevelRequiredExperience;
            //store only chunked points required to level up into one closest level
            if (config(key: 'level-up.audit.enabled')) {
                $user->experienceHistory()->create([
                    'points' => $poinsAdded - $remainingExperience,
                    'type' => $event->type,
                    'reason' => $event->reason,
                ]);
            }

            //promote user to new level
            $user->levelUp();

            if ($remainingExperience > 0) {
                //emit another PointsIncreasedEvent with those points
                $user->dispatchPoinsIncreasedEvent($remainingExperience, AuditType::Add->value, "remaining points from level {$currentUserLevel}");
            }
        }
    }
}
