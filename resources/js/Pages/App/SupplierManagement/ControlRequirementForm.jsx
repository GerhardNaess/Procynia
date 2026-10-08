import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import { anchorLabel, controlPointLabel, intervalLabel, levelLabel, requirementFormData, requirementKeepsRule, themeLabel, toggleCode } from './controlRequirements';

const LABEL = 'block text-base font-semibold text-slate-900';
const HINT = 'text-base text-slate-600';
const ERROR = 'text-base text-rose-700';
const INPUT = 'mt-1 min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const FIELDSET = 'rounded-xl border border-slate-200 px-4 py-3';
const CHOICE = 'flex min-h-10 items-start gap-2 py-1 text-base text-slate-900';

function FieldError({ message }) {
    return message ? <p className={ERROR}>{message}</p> : null;
}

/**
 * Nytt kontrollkrav / Rediger kontrollkrav — and, with `supplierId`, a requirement for that one
 * supplier, which has no rule. The rule is written the simple way (plan §5.3): «Alle leverandører» or
 * «Når ett av disse gjelder leverandøren», plus an optional criticality limit. The conditions are
 * named as a person reads them; no predicate name is shown.
 *
 * The anchor in Etterlevelse og revisjon is offered only when the server sent options — that is,
 * only to someone who can read Etterlevelse og revisjon.
 */
export default function ControlRequirementForm({ requirement = null, supplierId = null, options = {}, onDone, tr }) {
    const c = tr.control ?? {};
    const f = c.fields ?? {};
    const docs = tr.documents?.types ?? {};
    const form = useForm(requirementFormData(requirement));
    const forSupplier = supplierId !== null || requirement?.supplier_specific;
    const ruleModes = requirementKeepsRule(requirement) ? ['keep', 'all', 'conditions'] : ['all', 'conditions'];
    const ruleModeLabel = (mode) => ({
        keep: f.rule_keep ?? 'Behold regelen slik den er',
        all: f.rule_all ?? 'Alle leverandører',
        conditions: f.rule_conditions ?? 'Når ett av disse gjelder leverandøren',
    })[mode];
    const anchorOptions = options.anchor_options ?? null;
    const maxConditions = options.max_conditions ?? 6;
    const prefix = requirement ? `control-requirement-${requirement.id}` : (supplierId ? 'control-requirement-own' : 'control-requirement-new');

    const send = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: onDone };

        if (requirement) {
            form.patch(`/app/supplier-management/control-requirements/${requirement.id}`, done);
        } else if (supplierId) {
            form.post(`/app/supplier-management/${supplierId}/control-requirements`, done);
        } else {
            form.post('/app/supplier-management/control-requirements', done);
        }
    };

    const fillBasis = () => {
        const chosen = (anchorOptions ?? []).find((option) => String(option.id) === String(form.data.compliance_requirement_id));

        if (chosen) {
            form.setData('basis_text', anchorLabel(chosen));
        }
    };

    const text = (field, label, { hint = null, required = false, multiline = false } = {}) => (
        <div>
            <label htmlFor={`${prefix}-${field}`} className={LABEL}>{label}{required && <RequiredMark />}</label>
            {hint && <p className={HINT}>{hint}</p>}
            {multiline ? (
                <textarea id={`${prefix}-${field}`} rows={3} value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} className={INPUT} />
            ) : (
                <input id={`${prefix}-${field}`} type="text" value={form.data[field]} required={required} aria-required={required} onChange={(event) => form.setData(field, event.target.value)} className={INPUT} />
            )}
            <FieldError message={form.errors[field]} />
        </div>
    );

    const select = (field, label, values, name, { required = true, empty = null } = {}) => (
        <div>
            <label htmlFor={`${prefix}-${field}`} className={LABEL}>{label}{required && <RequiredMark />}</label>
            <select id={`${prefix}-${field}`} value={form.data[field]} required={required} aria-required={required} onChange={(event) => form.setData(field, event.target.value)} className={INPUT}>
                <option value="">{empty ?? '–'}</option>
                {values.map((value) => <option key={value} value={value}>{name(value)}</option>)}
            </select>
            <FieldError message={form.errors[field]} />
        </div>
    );

    return (
        <form onSubmit={send} className="mt-4 space-y-5 border-t border-slate-100 pt-5" data-testid="control-requirement-form">
            {form.errors.requirement && <p className={ERROR}>{form.errors.requirement}</p>}
            {text('title', f.title ?? 'Tittel', { hint: f.title_hint, required: true })}
            {text('description', f.description ?? 'Hva kravet innebærer', { multiline: true })}

            <div className="grid gap-4 sm:grid-cols-2">
                {select('theme', f.theme ?? 'Tema', options.themes ?? [], (value) => themeLabel(value, tr))}
                {select('control_point', f.control_point ?? 'Når kontrolleres kravet?', options.control_points ?? [], (value) => controlPointLabel(value, tr))}
                {select('control_interval_months', f.control_interval_months ?? 'Hvor ofte skal kravet kontrolleres?', (options.control_intervals ?? []).map(String), (value) => intervalLabel(Number(value), tr), { required: false, empty: intervalLabel(null, tr) })}
            </div>

            <fieldset className={FIELDSET} data-testid="control-requirement-level">
                <legend className={LABEL}>{f.level ?? 'Kravnivå'}<RequiredMark /></legend>
                {(options.levels ?? []).map((level) => (
                    <label key={level} className={CHOICE}>
                        <input type="radio" name={`${prefix}-level`} value={level} checked={form.data.level === level} onChange={() => form.setData('level', level)} className="mt-1 h-5 w-5 shrink-0" />
                        <span className="min-w-0 break-words"><span className="font-semibold">{levelLabel(level, tr)}</span> – {c.level_hints?.[level] ?? ''}</span>
                    </label>
                ))}
                <FieldError message={form.errors.level} />
            </fieldset>

            {! forSupplier && (
                <fieldset className={FIELDSET} data-testid="control-requirement-rule">
                    <legend className={LABEL}>{f.rule ?? 'Hvilke leverandører gjelder kravet?'}<RequiredMark /></legend>
                    {ruleModes.map((mode) => (
                        <label key={mode} className={CHOICE}>
                            <input type="radio" name={`${prefix}-rule-mode`} value={mode} checked={form.data.rule_mode === mode} onChange={() => form.setData('rule_mode', mode)} className="mt-1 h-5 w-5 shrink-0" />
                            <span className="min-w-0 break-words">
                                {ruleModeLabel(mode)}
                                {mode === 'keep' && requirement?.rule_text && <span className="block text-slate-700" data-testid="control-requirement-kept-rule">{requirement.rule_text}</span>}
                            </span>
                        </label>
                    ))}
                    {form.data.rule_mode === 'keep' && <p className={HINT}>{f.rule_keep_hint}</p>}
                    {form.data.rule_mode === 'conditions' && (
                        <div className="mt-2 border-t border-slate-100 pt-2" data-testid="control-requirement-conditions">
                            <p className={HINT}>{(f.conditions_hint ?? 'Velg opptil :max.').replace(':max', String(maxConditions))}</p>
                            <div className="mt-1 grid gap-x-6 sm:grid-cols-2">
                                {(options.conditions ?? []).map((condition) => (
                                    <label key={condition.value} className={CHOICE}>
                                        <input
                                            type="checkbox"
                                            checked={form.data.conditions.includes(condition.value)}
                                            onChange={() => form.setData('conditions', toggleCode(form.data.conditions, condition.value, maxConditions))}
                                            className="mt-1 h-5 w-5 shrink-0"
                                        />
                                        <span className="min-w-0 break-words">{condition.label}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}
                    <FieldError message={form.errors.conditions ?? form.errors['conditions.0'] ?? form.errors.rule_mode} />
                    {form.data.rule_mode !== 'keep' && <div className="mt-3 border-t border-slate-100 pt-2">
                        <p className={LABEL}>{f.criticality_scope ?? 'Kritikalitet'}</p>
                        {[['', f.scope_all ?? 'Alle kritikalitetsnivåer'], ['important', f.scope_important ?? 'Bare for Viktig og Kritisk'], ['critical', f.scope_critical ?? 'Bare for Kritisk']].map(([scope, label]) => (
                            <label key={scope || 'all'} className={CHOICE}>
                                <input type="radio" name={`${prefix}-scope`} value={scope} checked={form.data.criticality_scope === scope} onChange={() => form.setData('criticality_scope', scope)} className="mt-1 h-5 w-5 shrink-0" />
                                <span>{label}</span>
                            </label>
                        ))}
                    </div>}
                </fieldset>
            )}

            {text('guidance', f.guidance ?? 'Slik kontrollerer vi det', { hint: f.guidance_hint, multiline: true })}

            <fieldset className={FIELDSET}>
                <legend className={LABEL}>{f.accepted_document_types ?? 'Dokumentasjon som normalt godtas'}</legend>
                <p className={HINT}>{f.accepted_document_types_hint ?? 'Veiledende. Valgfritt.'}</p>
                <div className="mt-1 grid gap-x-6 sm:grid-cols-2">
                    {(options.document_types ?? []).map((type) => (
                        <label key={type} className={CHOICE}>
                            <input type="checkbox" checked={form.data.accepted_document_types.includes(type)} onChange={() => form.setData('accepted_document_types', toggleCode(form.data.accepted_document_types, type))} className="mt-1 h-5 w-5 shrink-0" />
                            <span className="min-w-0 break-words">{docs[type] ?? type}</span>
                        </label>
                    ))}
                </div>
            </fieldset>

            {anchorOptions !== null && (
                <div>
                    <label htmlFor={`${prefix}-anchor`} className={LABEL}>{f.compliance_requirement_id ?? 'Forankret i Etterlevelse og revisjon'}</label>
                    <p className={HINT}>{f.anchor_hint}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                        <select id={`${prefix}-anchor`} value={form.data.compliance_requirement_id} onChange={(event) => form.setData('compliance_requirement_id', event.target.value)} className={`${INPUT} mt-0 sm:flex-1`}>
                            <option value="">{f.anchor_none ?? 'Ingen forankring'}</option>
                            {requirement?.anchor && ! anchorOptions.some((option) => option.id === requirement.anchor.id) && (
                                <option value={String(requirement.anchor.id)}>{anchorLabel(requirement.anchor)}</option>
                            )}
                            {anchorOptions.map((option) => <option key={option.id} value={String(option.id)}>{anchorLabel(option)}</option>)}
                        </select>
                        {form.data.compliance_requirement_id && (
                            <button type="button" onClick={fillBasis} className={SECONDARY_ACTION}>{f.anchor_fill_basis ?? 'Bruk som grunnlag'}</button>
                        )}
                    </div>
                    <FieldError message={form.errors.compliance_requirement_id} />
                </div>
            )}

            {text('basis_text', f.basis_text ?? 'Grunnlag', { hint: f.basis_text_hint, multiline: true })}

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{c.catalogue?.submit ?? 'Lagre kontrollkrav'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}
