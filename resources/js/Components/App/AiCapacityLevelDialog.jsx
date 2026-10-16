import { PRIMARY_COLOURS, SECONDARY_COLOURS } from '../../Support/actionStyles';
import { interpolate, levelDescription, levelUnitsLabel } from '../../Support/aiCapacity';
import ActionDialog from './ActionDialog';

/**
 * Choose the AI capacity level (Nivå 1/2/3). Each level shows what it would include for this
 * customer — computed by the backend from its users and modules. No formula, no multiplier.
 * Room is left for a price per period once one is decided; none is shown until then.
 *
 * ActionDialog does the dialog work (A11Y-002): before, focus stayed on the page behind the overlay,
 * Escape did nothing and the page could be tabbed through under it.
 */
export default function AiCapacityLevelDialog({ isOpen, levels = [], texts = {}, locale = 'nb-NO', processing = false, onSelect, onClose }) {
    const title = texts.level_dialog_title ?? 'Velg nivå for AI-kapasitet';

    return (
        <ActionDialog
            isOpen={isOpen}
            onClose={onClose}
            closeDisabled={processing}
            titleId="ai-capacity-level-dialog-title"
            descriptionId="ai-capacity-level-dialog-intro"
            size="sm"
        >
            <div data-testid="ai-capacity-level-dialog">
                <h3 id="ai-capacity-level-dialog-title" className="text-lg font-semibold text-slate-900">{title}</h3>
                <p id="ai-capacity-level-dialog-intro" className="mt-2 text-base leading-6 text-slate-600">
                    {texts.level_dialog_intro ?? 'Kapasiteten gjelder per faktureringsperiode og deles av alle AI-funksjoner. Nytt nivå gjelder med en gang. Brukt kapasitet beholdes.'}
                </p>

                <ul className="mt-5 space-y-3">
                    {levels.map((level) => {
                        const description = levelDescription(level, texts);

                        return (
                            <li
                                key={level.key}
                                className={`rounded-xl border p-4 ${level.is_current ? 'border-slate-900 bg-slate-50' : 'border-slate-200'}`}
                                data-testid={`ai-capacity-level-${level.key}`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-base font-semibold text-slate-900">{level.name}</p>
                                        {description && <p className="text-base text-slate-600">{description}</p>}
                                        <p className="mt-1 text-base font-medium text-slate-900" data-testid={`ai-capacity-level-${level.key}-units`}>
                                            {levelUnitsLabel(level, texts, locale)}
                                        </p>
                                    </div>
                                    {level.is_current ? (
                                        <span className="text-base font-medium text-slate-700">{texts.level_current ?? 'Gjeldende nivå'}</span>
                                    ) : (
                                        <button
                                            type="button"
                                            disabled={processing}
                                            onClick={() => onSelect?.(level.key)}
                                            className={`rounded-lg px-4 py-2 text-base font-medium disabled:opacity-60 ${PRIMARY_COLOURS}`}
                                        >
                                            {interpolate(texts.level_select ?? 'Velg :level', { level: level.name })}
                                        </button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>

                <div className="mt-5 flex justify-end">
                    <button type="button" onClick={onClose} className={`rounded-lg px-4 py-2 text-base font-medium ${SECONDARY_COLOURS}`}>
                        {texts.level_cancel ?? 'Avbryt'}
                    </button>
                </div>
            </div>
        </ActionDialog>
    );
}
