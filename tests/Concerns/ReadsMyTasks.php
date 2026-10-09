<?php

namespace Tests\Concerns;

/**
 * Reading «Mine oppgaver» off the Oppfølging page's props: every task in display order, flattened out
 * of the Forfalt / Denne uken / Senere / Uten frist groups, optionally of one module.
 */
trait ReadsMyTasks
{
    /**
     * @param  array<string, mixed>  $infoCenter  the page's `infoCenter` prop
     * @return list<array<string, mixed>>
     */
    private function myTasksIn(array $infoCenter, ?string $module = null): array
    {
        return collect($infoCenter['my_tasks']['groups'] ?? [])
            ->flatMap(fn (array $group): array => $group['tasks'])
            ->when($module !== null, fn ($tasks) => $tasks->where('module', $module))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $infoCenter
     * @return list<array<string, mixed>>
     */
    private function wikiTasksIn(array $infoCenter): array
    {
        return $this->myTasksIn($infoCenter, 'wiki');
    }
}
