<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\AdminPageHelpResource;
use App\Models\AdminPageHelp;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Shared mechanism for displaying a context-sensitive Hjelp modal in Filament pages.
 * Each page defines its page_key. The help record is managed via AdminPageHelpResource.
 */
trait HasAdminPageHelp
{
    /**
     * Build a "Hjelp" header action from a resolved AdminPageHelp record.
     * Pass null to produce a hidden action when no active help record exists.
     */
    protected function buildPageHelpAction(?AdminPageHelp $help): Action
    {
        return Action::make('pageHelp')
            ->label('Hjelp')
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('gray')
            ->visible($help !== null)
            ->modalHeading($help?->title ?? 'Hjelp')
            ->modalDescription($help?->description)
            ->modalContent(view('filament.components.page-help', [
                'intro'    => $help?->intro,
                'sections' => $help?->sections ?? [],
                'editUrl'  => ($help && AdminPageHelpResource::canAccess())
                    ? AdminPageHelpResource::getUrl('edit', ['record' => $help->getKey()])
                    : null,
                'editLabel' => 'Rediger hjelpetekst',
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Lukk');
    }

    /**
     * Fetch the first active AdminPageHelp record matching any of the given keys.
     * Keys are tried in order — supports primary key + legacy fallback.
     */
    protected static function fetchPageHelp(string ...$keys): ?AdminPageHelp
    {
        foreach ($keys as $key) {
            $record = AdminPageHelp::query()
                ->where('page_key', $key)
                ->where('is_active', true)
                ->first();

            if ($record !== null) {
                return $record;
            }
        }

        return null;
    }
}
