<?php

namespace App\Services\MyTasks;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One module's contribution to «Mine oppgaver».
 *
 * A source reads its own module's rows and nothing else, and owns every rule about them: who the work
 * belongs to, when it is done, what is due when. MyTasksService only gathers and orders.
 *
 * ACCESS IS THE SOURCE'S JOB, AND COMES FIRST. A source returns nothing unless the customer holds
 * the module and the person may read it — through the module's own access service, never a check
 * restated here — and returns only objects that person may read. Losing access therefore empties
 * the source on the next read; nothing has to be cleaned up.
 *
 * To add a module (fase 6D): implement this, and add it to MyTasksService's constructor.
 */
interface MyTaskSource
{
    /** The module key, as in config/procynia_modules.php. */
    public function module(): string;

    /**
     * The work still on this person, already narrowed to what they may read. The caller has checked
     * that the user is active and belongs to $customerId.
     *
     * @return Collection<int, MyTask>
     */
    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection;
}
