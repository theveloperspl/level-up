<?php

namespace LevelUp\Experience\Concerns;

use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use LevelUp\Experience\Enums\AuditType;
use LevelUp\Experience\Events\PointsDecreased;
use LevelUp\Experience\Events\PointsIncreased;
use LevelUp\Experience\Events\UserLevelledUp;
use LevelUp\Experience\Models\Experience;
use LevelUp\Experience\Models\ExperienceAudit;
use LevelUp\Experience\Models\Level;
use LevelUp\Experience\Services\MultiplierService;

trait GiveExperience
{
    protected ?Collection $multiplierData = null;

    public function addPoints(
        int $amount,
        int $multiplier = null,
        string $type = null,
        string $reason = null
    ): Experience {
        if ($type === null) {
            $type = AuditType::Add->value;
        }

        $lastLevel = Level::orderByDesc(column: 'level')->first();
        if($amount > $lastLevel->next_level_experience) {
            $amount = $lastLevel->next_level_experience;
        }

        /**
         * If the Multiplier Service is enabled, apply the Multipliers.
         */
        if (config(key: 'level-up.multiplier.enabled') && file_exists(filename: config(key: 'level-up.multiplier.path'))) {
            $amount = $this->getMultipliers(amount: $amount);
        }

        if ($multiplier) {
            $amount *= $multiplier;
        }

        /**
         * If the User does not have an Experience record, create one.
         */
        if ($this->experience()->doesntExist()) {
            $level = Level::query()
                ->where(column: 'next_level_experience', operator: '<=', value: $amount)
                ->orderByDesc(column: 'next_level_experience')
                ->first();

            $experience = $this->experience()->create(attributes: [
                'level_id' => $level->level ?? config(key: 'level-up.starting_level'),
                'experience_points' => $amount,
            ]);

            /**
             * This is updating the User's level_id column, which is not the same as the Experience's level_id column.
             */
            $this->fill([
                'level_id' => $experience->level_id,
            ])->save();

            if ($level?->level > config(key: 'level-up.starting_level')) {
                Event::dispatch(event: new UserLevelledUp(user: $this, level: $level->level));
            }

            $this->dispatchPoinsIncreasedEvent($amount, $type, $reason);

            return $this->experience;
        }
        /**
         * If the User does have an Experience record, update it.
         */
        if ($this->levelCapExceedsUserLevel()) {
            return $this->experience;
        }

        $this->experience->increment(column: 'experience_points', amount: $amount);

        $this->dispatchPoinsIncreasedEvent($amount, $type, $reason);

        return $this->experience;
    }

    protected function getMultipliers(int $amount): int
    {
        $multiplierService = app(MultiplierService::class, [
            'data' => $this->multiplierData ? $this->multiplierData->toArray() : [],
        ]);

        return $multiplierService(points: $amount);
    }

    public function experience(): HasOne
    {
        return $this->hasOne(related: Experience::class);
    }

    public function experienceHistory(): HasMany
    {
        return $this->hasMany(related: ExperienceAudit::class);
    }

    public function getPoints(): int
    {
        return $this->experience->experience_points;
    }

    public function dispatchPoinsIncreasedEvent(int $amount, string $type, ?string $reason): void
    {
        event(new PointsIncreased(
            pointsAdded: $amount,
            totalPoints: $this->experience->experience_points,
            type: $type,
            reason: $reason,
            user: $this,
        ));
    }

    protected function levelCapExceedsUserLevel(): bool
    {
        return config(key: 'level-up.level_cap.enabled')
            && $this->getCurrentLevel() >= config(key: 'level-up.level_cap.level')
            && ! (config(key: 'level-up.level_cap.points_continue'));
    }

    public function getCurrentLevel(): int
    {
        return $this->experience->status->level;
    }

    public function deductPoints(int $amount): Experience
    {
        $this->experience->decrement(column: 'experience_points', amount: $amount);

        event(new PointsDecreased(pointsDecreasedBy: $amount, totalPoints: $this->experience->experience_points));

        return $this->experience;
    }

    /**
     * @throws \Exception
     */
    public function setPoints(int $amount): Experience
    {
        if (! $this->experience()->exists()) {
            throw new Exception(message: 'User has no experience record.');
        }

        $this->experience->update(attributes: [
            'experience_points' => $amount,
        ]);

        return $this->experience;
    }

    public function withMultiplierData(array $data): static
    {
        $this->multiplierData = collect($data);

        return $this;
    }

    public function nextLevelAt(int $checkAgainst = null, bool $showAsPercentage = false): int
    {
        $nextLevel = Level::firstWhere(column: 'level', operator: '=', value: is_null($checkAgainst) ? $this->getCurrentLevel() + 1 : $checkAgainst);

        if ($this->levelCapExceedsUserLevel()) {
            return 0;
        }

        if (! $nextLevel || $nextLevel->next_level_experience === null) {
            return 0;
        }

        $currentLevelExperience = Level::firstWhere(column: 'level', operator: '=', value: $this->getCurrentLevel())->next_level_experience;

        if ($showAsPercentage) {
            return (int) ((($this->getPoints() - $currentLevelExperience) / ($nextLevel->next_level_experience - $currentLevelExperience)) * 100);
        }

        return max(0, ($nextLevel->next_level_experience - $currentLevelExperience) - ($this->getPoints() - $currentLevelExperience));
    }

    public function levelUp(): void
    {
        if (config(key: 'level-up.level_cap.enabled') && $this->getCurrentLevel() >= config(key: 'level-up.level_cap.level')) {
            return;
        }

        $nextLevel = Level::firstWhere(column: 'level', operator: $this->getCurrentLevel() + 1);

        $this->experience->status()->associate(model: $nextLevel);
        $this->experience->save();

        $this->update(attributes: [
            'level_id' => $nextLevel->level,
        ]);

        event(new UserLevelledUp(user: $this, level: $this->getCurrentLevel()));
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(related: Level::class);
    }
}
