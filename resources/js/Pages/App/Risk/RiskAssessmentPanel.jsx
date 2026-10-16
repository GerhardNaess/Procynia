import { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../Components/App/FormField';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { announce } from '../../../Support/announcer';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, INPUT, LABEL } from '../../../Support/formStyles';
import RequiredMark from './RequiredMark';
import { previewAnnouncement, previewKey } from './riskA11y';
import { RISK_LEVEL_TONES, previewLevel } from './riskLevel';
import useInlinePanelFocus from './useInlinePanelFocus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString('nb-NO') : '—');

/**
 * The risk's assessment history: the latest one up top, the earlier ones below, newest first.
 * Assessments are never edited; «Ny vurdering» adds the next one, and is offered only when the
 * server said this person holds risk.assess for the risk's area.
 *
 * `hasCurrentAcceptance` is the server's current acceptance (Aksept av restrisiko). An acceptance belongs
 * to one assessment, so the form says before saving that a new one makes it historical.
 */
export default function RiskAssessmentPanel({ riskId, assessments, criteria, canAssess, hasCurrentAcceptance = false, tr }) {
    const ta = tr.assessment ?? {};
    const [creating, setCreating] = useState(false);
    const [latest, ...earlier] = assessments;
    // «Ny vurdering» is hidden while its form is open: focus into the form, back to the button after
    // Avbryt or a save (A11Y-014).
    const formPanelRef = useRef(null);
    useInlinePanelFocus(creating, { panelRef: formPanelRef, returnIds: ['risk-assessment-create', 'risk-assessment-heading'] });

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="risk-assessment-heading" className="text-lg font-semibold text-slate-950">{ta.heading ?? 'Risikovurdering'}</h2>
                    <p className="mt-1 text-base text-slate-600">
                        {ta.status_note ?? 'Hvor alvorlig risikoen er. Siste vurdering gjelder.'}
                    </p>
                </div>
                {canAssess && ! creating && (
                    <button id="risk-assessment-create" type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION}>
                        {ta.create ?? 'Ny vurdering'}
                    </button>
                )}
            </div>

            {creating && (
                <AssessmentForm
                    formRef={formPanelRef}
                    riskId={riskId}
                    criteria={criteria}
                    supersedesAcceptance={hasCurrentAcceptance}
                    ta={ta}
                    tr={tr}
                    onDone={() => setCreating(false)}
                />
            )}

            {latest ? (
                <div className="mt-6 space-y-6">
                    <div>
                        <h3 className="text-base font-semibold uppercase tracking-wide text-slate-500">{ta.latest ?? 'Siste vurdering'}</h3>
                        <AssessmentDetails assessment={latest} ta={ta} tr={tr} />
                    </div>

                    {earlier.length > 0 && (
                        <div>
                            <h3 className="text-base font-semibold uppercase tracking-wide text-slate-500">{ta.history ?? 'Tidligere vurderinger'}</h3>
                            <p className="mt-1 text-base text-slate-600">
                                {ta.history_hint ?? 'Vurderinger endres aldri. En feil rettes med en ny vurdering.'}
                            </p>
                            <ol className="mt-3 divide-y divide-slate-100 rounded-2xl border border-slate-200">
                                {earlier.map((assessment) => (
                                    <li key={assessment.id} className="px-4 py-2">
                                        <AssessmentDetails assessment={assessment} ta={ta} tr={tr} compact />
                                    </li>
                                ))}
                            </ol>
                        </div>
                    )}
                </div>
            ) : (
                ! creating && (
                    <div className="mt-6 rounded-2xl border border-dashed border-slate-300 p-6">
                        <p className="text-base font-semibold text-slate-900">{ta.none_title ?? 'Risikoen er ikke vurdert ennå'}</p>
                        <p className="mt-1 text-base text-slate-600">
                            {ta.none_hint ?? 'Vurder sannsynlighet og konsekvens for å se hvor alvorlig risikoen er.'}
                        </p>
                    </div>
                )
            )}
        </section>
    );
}

function LevelBadge({ result, ta }) {
    const levels = ta.levels ?? {};

    return (
        <StatusBadge tone={RISK_LEVEL_TONES[result.level] ?? 'slate'}>
            {levels[result.level] ?? result.level}
        </StatusBadge>
    );
}

/**
 * One of the two ratings. The explanation of the term used to be a `title` tooltip — reachable only by
 * hovering with a mouse (1.3.1, 2.1.1) — so the latest assessment now shows it as text; the earlier
 * ones (`compact`) leave it out rather than repeat it in every entry.
 */
function Rating({ title, hint, result, ta, compact = false }) {
    const likelihoodLabels = ta.likelihood_labels ?? {};
    const consequenceLabels = ta.consequence_labels ?? {};

    return (
        <div className="rounded-2xl bg-slate-50 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-base font-semibold text-slate-700">{title}</span>
                {result && <LevelBadge result={result} ta={ta} />}
            </div>
            {! compact && hint && <p className="mt-1 text-base text-slate-600">{hint}</p>}
            {result ? (
                <p className="mt-2 text-base text-slate-700">
                    {ta.likelihood ?? 'Sannsynlighet'}: {result.likelihood} {likelihoodLabels[result.likelihood] ?? ''}
                    {' · '}
                    {ta.consequence ?? 'Konsekvens'}: {result.consequence} {consequenceLabels[result.consequence] ?? ''}
                    {' · '}
                    {(ta.score ?? 'Score :score').replace(':score', result.score)}
                </p>
            ) : (
                <p className="mt-2 text-base text-slate-600">{ta.residual_none ?? 'Ikke vurdert'}</p>
            )}
        </div>
    );
}

function AssessmentDetails({ assessment, ta, tr, compact = false }) {
    const assessor = assessment.assessed_by_name ?? (ta.unknown_assessor ?? 'ukjent bruker');

    return (
        <div className={compact ? 'space-y-2 py-2' : 'mt-3 space-y-3'}>
            <p className="text-base text-slate-600">
                {(ta.assessed_by ?? 'Vurdert av :name').replace(':name', assessor)} · {formatDate(assessment.assessed_at)}
            </p>
            <div className="grid gap-3 md:grid-cols-2">
                <Rating
                    title={ta.inherent ?? 'Iboende risiko'}
                    hint={ta.inherent_hint}
                    result={assessment.inherent}
                    ta={ta}
                    compact={compact}
                />
                <Rating
                    title={ta.residual ?? 'Restrisiko'}
                    hint={ta.residual_hint}
                    result={assessment.residual}
                    ta={ta}
                    compact={compact}
                />
            </div>
            <div>
                <span className="text-base font-semibold text-slate-700">{ta.rationale ?? 'Begrunnelse'}</span>
                <p className="mt-1 whitespace-pre-line text-base leading-6 text-slate-800">{assessment.rationale}</p>
            </div>
            <AssessedDescription description={assessment.risk_description} ta={ta} tr={tr} />
        </div>
    );
}

/**
 * What was assessed: årsak, hendelse and konsekvens as they read when the assessment was registered,
 * from the assessment's own snapshot. Older assessments have none, and say so rather than borrow
 * today's text.
 *
 * When the risk still reads exactly as assessed, repeating the same three parts under every
 * assessment adds nothing, so the snapshot is folded behind «Uendret siden vurderingen» — still one
 * click away. Once the description has changed, the snapshot is shown open with the notice.
 */
function AssessedDescription({ description, ta, tr }) {
    if (! description) {
        return (
            <p className="text-base text-slate-500">
                {ta.assessed_description_missing ?? 'Risikobeskrivelsen ble ikke lagret sammen med denne vurderingen.'}
            </p>
        );
    }

    const ts = tr.structured ?? {};
    const parts = [
        { field: 'cause', label: ts.field_cause ?? 'Årsak' },
        { field: 'event', label: ts.field_event ?? 'Hendelse' },
        { field: 'consequence', label: ts.field_consequence ?? 'Konsekvens' },
    ];

    const heading = ta.assessed_description ?? 'Vurdert risikobeskrivelse';
    const snapshot = (
        <dl className="mt-1 grid gap-x-4 gap-y-1 sm:grid-cols-[auto_1fr]">
            {parts.map(({ field, label }) => (
                <div key={field} className="contents">
                    <dt className="text-base text-slate-600">{label}</dt>
                    <dd className="whitespace-pre-line text-base leading-6 text-slate-800">{description[field]}</dd>
                </div>
            ))}
        </dl>
    );

    if (! description.changed_since) {
        return (
            <details>
                <summary className="cursor-pointer text-base text-slate-600">
                    <span className="font-semibold text-slate-700">{heading}</span>
                    {' · '}{ta.assessed_description_unchanged ?? 'Uendret siden vurderingen'}
                </summary>
                {snapshot}
            </details>
        );
    }

    return (
        <div>
            <span className="text-base font-semibold text-slate-700">{heading}</span>
            <p className="mt-1 text-base text-amber-700">
                {ta.assessed_description_changed ?? 'Risikobeskrivelsen er endret etter denne vurderingen.'}
            </p>
            {snapshot}
        </div>
    );
}

function ScaleSelect({ id, label, value, onChange, values, labels, error, required = false, ta }) {
    const { errorId, controlProps } = fieldA11y({ id, hasError: Boolean(error), required });

    return (
        <div>
            <label htmlFor={id} className={LABEL}>{label}{required && <RequiredMark />}</label>
            <select {...controlProps} value={value} onChange={(event) => onChange(event.target.value)} className={`mt-1 ${INPUT}`}>
                <option value="">{ta.choose ?? 'Velg'}</option>
                {values.map((step) => (
                    <option key={step} value={String(step)}>{step} – {labels[step] ?? step}</option>
                ))}
            </select>
            {error && <p id={errorId} className={ERROR}>{error}</p>}
        </div>
    );
}

/**
 * The level the chosen pair will get. It used to be an aria-live region that mounted with the form
 * (and with Restrisiko), so what it first said could be lost; now the text is plain and a change of
 * level is spoken once through the app's permanent region (4.1.3, A11Y-112). Choosing the first
 * scale of a pair says nothing; the level is said once both are set, and again only if it changes.
 */
function Preview({ criteria, likelihood, consequence, ta }) {
    const result = previewLevel(criteria, likelihood, consequence);
    const key = previewKey(result);
    const lastKey = useRef(key);

    useEffect(() => {
        if (key === lastKey.current) {
            return;
        }

        lastKey.current = key;

        const message = previewAnnouncement(result, ta);

        if (message) {
            announce(message);
        }
        // The key carries the result: the same level and score are the same message.
    }, [key]);

    return (
        <div className="flex flex-wrap items-center gap-2 text-base text-slate-600">
            <span>{ta.level_preview ?? 'Beregnet nivå'}:</span>
            {result ? (
                <>
                    <LevelBadge result={result} ta={ta} />
                    <span>{(ta.score ?? 'Score :score').replace(':score', result.score)}</span>
                </>
            ) : (
                <span>—</span>
            )}
        </div>
    );
}

function AssessmentForm({ formRef, riskId, criteria, supersedesAcceptance, ta, tr, onDone }) {
    const [withResidual, setWithResidual] = useState(false);
    const focusFirstError = useFocusFirstError(formRef);
    const form = useForm({
        inherent_likelihood: '',
        inherent_consequence: '',
        residual_likelihood: '',
        residual_consequence: '',
        rationale: '',
    });

    const likelihoodLabels = ta.likelihood_labels ?? {};
    const consequenceLabels = ta.consequence_labels ?? {};
    const likelihoods = criteria?.likelihood ?? [];
    const consequences = criteria?.consequence ?? [];

    const toggleResidual = () => {
        if (withResidual) {
            form.setData((data) => ({ ...data, residual_likelihood: '', residual_consequence: '' }));
        }
        setWithResidual(! withResidual);
    };

    const submit = (event) => {
        event.preventDefault();

        // «Lagre vurdering» stays focusable while saving (aria-disabled), so the guard lives here.
        if (form.processing) {
            return;
        }

        form.transform((data) => ({
            ...data,
            residual_likelihood: data.residual_likelihood || null,
            residual_consequence: data.residual_consequence || null,
        }));
        form.post(`/app/risk/risks/${riskId}/assessments`, {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onDone(); },
            onError: () => focusFirstError(),
        });
    };

    const rationale = fieldA11y({ id: 'assessment-rationale', hasHint: true, hasError: Boolean(form.errors.rationale), required: true });

    return (
        <form ref={formRef} onSubmit={submit} className="mt-6 space-y-5 rounded-2xl border border-slate-200 p-5">
            <h3 className="text-base font-semibold text-slate-950">{ta.form_heading ?? 'Ny risikovurdering'}</h3>
            {supersedesAcceptance && (
                <p className="rounded-xl bg-amber-50 px-3 py-2 text-base text-amber-900">
                    {ta.supersedes_acceptance ?? 'En ny vurdering gjør dagens aksept historisk. Ny restrisiko må eventuelt aksepteres på nytt.'}
                </p>
            )}

            <fieldset className="space-y-3">
                <legend className="text-base font-semibold text-slate-900">
                    {ta.inherent ?? 'Iboende risiko'}
                    <span className="ml-2 font-normal text-slate-600">{ta.inherent_hint ?? 'Før effekten av kontroller og tiltak.'}</span>
                </legend>
                <div className="grid gap-4 md:grid-cols-2">
                    <ScaleSelect
                        id="assessment-inherent-likelihood"
                        label={ta.likelihood ?? 'Sannsynlighet'}
                        value={form.data.inherent_likelihood}
                        onChange={(value) => form.setData('inherent_likelihood', value)}
                        values={likelihoods}
                        labels={likelihoodLabels}
                        error={form.errors.inherent_likelihood}
                        required
                        ta={ta}
                    />
                    <ScaleSelect
                        id="assessment-inherent-consequence"
                        label={ta.consequence ?? 'Konsekvens'}
                        value={form.data.inherent_consequence}
                        onChange={(value) => form.setData('inherent_consequence', value)}
                        values={consequences}
                        labels={consequenceLabels}
                        error={form.errors.inherent_consequence}
                        required
                        ta={ta}
                    />
                </div>
                <Preview criteria={criteria} likelihood={form.data.inherent_likelihood} consequence={form.data.inherent_consequence} ta={ta} />
            </fieldset>

            <div>
                <button type="button" onClick={toggleResidual} className="text-base font-semibold text-violet-700 hover:text-violet-900">
                    {withResidual ? (ta.remove_residual ?? 'Fjern restrisiko') : (ta.add_residual ?? 'Vurder også restrisiko')}
                </button>
            </div>

            {withResidual && (
                <fieldset className="space-y-3">
                    <legend className="text-base font-semibold text-slate-900">
                        {ta.residual ?? 'Restrisiko'}
                        <span className="ml-2 font-normal text-slate-600">
                            {ta.residual_hint ?? 'Det som gjenstår etter eksisterende kontroller og tiltak, etter din faglige vurdering.'}
                        </span>
                    </legend>
                    <div className="grid gap-4 md:grid-cols-2">
                        <ScaleSelect
                            id="assessment-residual-likelihood"
                            label={ta.likelihood ?? 'Sannsynlighet'}
                            value={form.data.residual_likelihood}
                            onChange={(value) => form.setData('residual_likelihood', value)}
                            values={likelihoods}
                            labels={likelihoodLabels}
                            error={form.errors.residual_likelihood}
                            required
                            ta={ta}
                        />
                        <ScaleSelect
                            id="assessment-residual-consequence"
                            label={ta.consequence ?? 'Konsekvens'}
                            value={form.data.residual_consequence}
                            onChange={(value) => form.setData('residual_consequence', value)}
                            values={consequences}
                            labels={consequenceLabels}
                            error={form.errors.residual_consequence}
                            required
                            ta={ta}
                        />
                    </div>
                    <Preview criteria={criteria} likelihood={form.data.residual_likelihood} consequence={form.data.residual_consequence} ta={ta} />
                </fieldset>
            )}

            <div>
                <label htmlFor="assessment-rationale" className={LABEL}>{ta.field_rationale ?? 'Kort begrunnelse'}<RequiredMark /></label>
                <p id={rationale.hintId} className="text-base text-slate-600">{ta.field_rationale_hint ?? 'Hva bygger vurderingen på?'}</p>
                <textarea
                    {...rationale.controlProps}
                    rows={3}
                    value={form.data.rationale}
                    onChange={(event) => form.setData('rationale', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.rationale && <p id={rationale.errorId} className={ERROR}>{form.errors.rationale}</p>}
            </div>

            <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />

            <div className="flex flex-wrap items-center gap-3">
                <button type="submit" aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (ta.save ?? 'Lagre vurdering')}
                </button>
                <button type="button" onClick={() => { form.reset(); form.clearErrors(); onDone(); }} className={SECONDARY_ACTION}>
                    {tr.cancel ?? 'Avbryt'}
                </button>
                <span className="text-base text-slate-500">{ta.immutable_note ?? 'En lagret vurdering kan ikke endres.'}</span>
            </div>
        </form>
    );
}
