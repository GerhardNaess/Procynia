import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { RISK_LEVEL_TONES, previewLevel } from './riskLevel';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString('nb-NO') : '—');

/**
 * The risk's assessment history: the latest one up top, the earlier ones below, newest first.
 * Assessments are never edited; «Ny vurdering» adds the next one, and is offered only when the
 * server said this person holds risk.assess for the risk's area.
 */
export default function RiskAssessmentPanel({ riskId, assessments, criteria, canAssess, tr }) {
    const ta = tr.assessment ?? {};
    const [creating, setCreating] = useState(false);
    const [latest, ...earlier] = assessments;

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">{ta.heading ?? 'Risikovurdering'}</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        {ta.status_note ?? 'Status sier hvor risikoen er i behandlingen. Risikonivået kommer fra siste vurdering.'}
                    </p>
                </div>
                {canAssess && ! creating && (
                    <button type="button" onClick={() => setCreating(true)} className={PRIMARY_ACTION}>
                        {ta.create ?? 'Ny vurdering'}
                    </button>
                )}
            </div>

            {creating && (
                <AssessmentForm
                    riskId={riskId}
                    criteria={criteria}
                    ta={ta}
                    tr={tr}
                    onDone={() => setCreating(false)}
                />
            )}

            {latest ? (
                <div className="mt-6 space-y-6">
                    <div>
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{ta.latest ?? 'Siste vurdering'}</h3>
                        <AssessmentDetails assessment={latest} ta={ta} />
                    </div>

                    {earlier.length > 0 && (
                        <div>
                            <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{ta.history ?? 'Tidligere vurderinger'}</h3>
                            <p className="mt-1 text-sm text-slate-600">
                                {ta.history_hint ?? 'Vurderinger endres aldri. En feil rettes med en ny vurdering.'}
                            </p>
                            <ol className="mt-3 divide-y divide-slate-100 rounded-2xl border border-slate-200">
                                {earlier.map((assessment) => (
                                    <li key={assessment.id} className="px-4 py-2">
                                        <AssessmentDetails assessment={assessment} ta={ta} compact />
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
                        <p className="mt-1 text-sm text-slate-600">
                            {ta.none_hint ?? 'Når noen med rett til å vurdere registrerer en vurdering, vises den her.'}
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

function Rating({ title, hint, result, ta }) {
    const likelihoodLabels = ta.likelihood_labels ?? {};
    const consequenceLabels = ta.consequence_labels ?? {};

    return (
        <div className="rounded-2xl bg-slate-50 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-semibold text-slate-700" title={hint}>{title}</span>
                {result && <LevelBadge result={result} ta={ta} />}
            </div>
            {result ? (
                <p className="mt-2 text-sm text-slate-700">
                    {ta.likelihood ?? 'Sannsynlighet'}: {result.likelihood} {likelihoodLabels[result.likelihood] ?? ''}
                    {' · '}
                    {ta.consequence ?? 'Konsekvens'}: {result.consequence} {consequenceLabels[result.consequence] ?? ''}
                    {' · '}
                    {(ta.score ?? 'Score :score').replace(':score', result.score)}
                </p>
            ) : (
                <p className="mt-2 text-sm text-slate-500">{ta.residual_none ?? 'Ikke vurdert'}</p>
            )}
        </div>
    );
}

function AssessmentDetails({ assessment, ta, compact = false }) {
    const assessor = assessment.assessed_by_name ?? (ta.unknown_assessor ?? 'ukjent bruker');

    return (
        <div className={compact ? 'space-y-2 py-2' : 'mt-3 space-y-3'}>
            <p className="text-sm text-slate-600">
                {(ta.assessed_by ?? 'Vurdert av :name').replace(':name', assessor)} · {formatDate(assessment.assessed_at)}
            </p>
            <div className="grid gap-3 md:grid-cols-2">
                <Rating
                    title={ta.inherent ?? 'Iboende risiko'}
                    hint={ta.inherent_hint}
                    result={assessment.inherent}
                    ta={ta}
                />
                <Rating
                    title={ta.residual ?? 'Restrisiko'}
                    hint={ta.residual_hint}
                    result={assessment.residual}
                    ta={ta}
                />
            </div>
            <div>
                <span className="text-sm font-semibold text-slate-700">{ta.rationale ?? 'Begrunnelse'}</span>
                <p className="mt-1 whitespace-pre-line text-base leading-6 text-slate-800">{assessment.rationale}</p>
            </div>
        </div>
    );
}

function ScaleSelect({ id, label, value, onChange, values, labels, error, ta }) {
    return (
        <div>
            <label htmlFor={id} className={LABEL}>{label}</label>
            <select id={id} value={value} onChange={(event) => onChange(event.target.value)} className={`mt-1 ${INPUT}`}>
                <option value="">{ta.choose ?? 'Velg'}</option>
                {values.map((step) => (
                    <option key={step} value={String(step)}>{step} – {labels[step] ?? step}</option>
                ))}
            </select>
            {error && <p className="mt-1 text-sm text-rose-600">{error}</p>}
        </div>
    );
}

function Preview({ criteria, likelihood, consequence, ta }) {
    const result = previewLevel(criteria, likelihood, consequence);

    return (
        <div className="flex items-center gap-2 text-sm text-slate-600" aria-live="polite">
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

function AssessmentForm({ riskId, criteria, ta, tr, onDone }) {
    const [withResidual, setWithResidual] = useState(false);
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
        form.transform((data) => ({
            ...data,
            residual_likelihood: data.residual_likelihood || null,
            residual_consequence: data.residual_consequence || null,
        }));
        form.post(`/app/risk/risks/${riskId}/assessments`, {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onDone(); },
        });
    };

    return (
        <form onSubmit={submit} className="mt-6 space-y-5 rounded-2xl border border-slate-200 p-5">
            <h3 className="text-base font-semibold text-slate-950">{ta.form_heading ?? 'Ny risikovurdering'}</h3>

            <fieldset className="space-y-3">
                <legend className="text-sm font-semibold text-slate-900">
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
                        ta={ta}
                    />
                </div>
                <Preview criteria={criteria} likelihood={form.data.inherent_likelihood} consequence={form.data.inherent_consequence} ta={ta} />
            </fieldset>

            <div>
                <button type="button" onClick={toggleResidual} className="text-sm font-semibold text-violet-700 hover:text-violet-900">
                    {withResidual ? (ta.remove_residual ?? 'Fjern restrisiko') : (ta.add_residual ?? 'Vurder også restrisiko')}
                </button>
            </div>

            {withResidual && (
                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-slate-900">
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
                            ta={ta}
                        />
                    </div>
                    <Preview criteria={criteria} likelihood={form.data.residual_likelihood} consequence={form.data.residual_consequence} ta={ta} />
                </fieldset>
            )}

            <div>
                <label htmlFor="assessment-rationale" className={LABEL}>{ta.field_rationale ?? 'Kort begrunnelse'}</label>
                <p className="text-sm text-slate-600">{ta.field_rationale_hint ?? 'Hva bygger vurderingen på?'}</p>
                <textarea
                    id="assessment-rationale"
                    rows={3}
                    value={form.data.rationale}
                    onChange={(event) => form.setData('rationale', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.rationale && <p className="mt-1 text-sm text-rose-600">{form.errors.rationale}</p>}
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (ta.save ?? 'Lagre vurdering')}
                </button>
                <button type="button" onClick={() => { form.reset(); form.clearErrors(); onDone(); }} className={SECONDARY_ACTION}>
                    {tr.cancel ?? 'Avbryt'}
                </button>
                <span className="text-sm text-slate-500">{ta.immutable_note ?? 'En lagret vurdering kan ikke endres.'}</span>
            </div>
        </form>
    );
}
