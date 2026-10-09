<?php

namespace App\Observers;

use App\Services\Notifications\AssignmentNotifier;
use Illuminate\Database\Eloquent\Model;

/**
 * Announces a change of owner on the styringsmoduler's objects (AssignmentNotifier::MODELS): a new
 * object with an owner, or an update that changed the owner. Every write path raises these events,
 * so a handoff from another module is announced exactly like a form save. A notification that cannot
 * be prepared never fails the save it is about.
 */
class AssignmentObserver
{
    public function __construct(
        private readonly AssignmentNotifier $notifier,
    ) {}

    public function created(Model $model): void
    {
        rescue(fn () => $this->notifier->announce($model, true), null, true);
    }

    public function updated(Model $model): void
    {
        rescue(fn () => $this->notifier->announce($model, false), null, true);
    }
}
