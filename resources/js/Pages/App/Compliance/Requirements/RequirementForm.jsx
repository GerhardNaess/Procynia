import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import RequiredMark from '../../Risk/RequiredMark';
import { reviewIntervalLabel } from './complianceRequirement';

const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

/**
 * The fields of a requirement, shared by Nytt krav and Rediger. The source and owner lists come from
 * the server, which checks both again on save. There is no status field: a requirement is created
 * active and moves only through Sett som utgått and Gjenåpne. Nor is there anything about whether
 * the requirement is met — that is assessed elsewhere.
 */
export default function RequirementForm({ form, onSubmit, onCancel, sourceOptions = [], ownerOptions = [], reviewIntervals = [], tr }) {
    return (
        <form onSubmit={onSubmit} className="space-y-5">
            <div className="grid gap-5 md:grid-cols-2">
                <div>
                    <label htmlFor="compliance-requirement-source" className={LABEL}>{tr.field_source ?? 'Kravkilde'}<RequiredMark /></label>
                    <select
                        id="compliance-requirement-source"
                        required
                        aria-required="true"
                        aria-describedby="compliance-requirement-source-hint"
                        value={form.data.source_id}
                        onChange={(event) => form.setData('source_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_source ?? 'Velg kravkilde'}</option>
                        {sourceOptions.map((source) => (
                            <option key={source.id} value={source.id}>{source.label}</option>
                        ))}
                    </select>
                    <p id="compliance-requirement-source-hint" className={HINT}>{tr.field_source_hint ?? 'Hvor kravet kommer fra.'}</p>
                    {form.errors.source_id && <p className={ERROR}>{form.errors.source_id}</p>}
                </div>

                <div>
                    <label htmlFor="compliance-requirement-reference" className={LABEL}>{tr.field_reference ?? 'Referanse'}</label>
                    <input
                        id="compliance-requirement-reference"
                        type="text"
                        maxLength={100}
                        aria-describedby="compliance-requirement-reference-hint"
                        value={form.data.reference}
                        onChange={(event) => form.setData('reference', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="compliance-requirement-reference-hint" className={HINT}>
                        {tr.field_reference_hint ?? 'Valgfritt. For eksempel «A.5.1» eller «§ 4-2». Må være unik innen kravkilden.'}
                    </p>
                    {form.errors.reference && <p className={ERROR}>{form.errors.reference}</p>}
                </div>
            </div>

            <div>
                <label htmlFor="compliance-requirement-title" className={LABEL}>{tr.field_title ?? 'Tittel'}<RequiredMark /></label>
                <p id="compliance-requirement-title-hint" className={HINT}>{tr.field_title_hint ?? 'Kort navn på kravet.'}</p>
                <input
                    id="compliance-requirement-title"
                    type="text"
                    required
                    aria-required="true"
                    aria-describedby="compliance-requirement-title-hint"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
            </div>

            <div>
                <label htmlFor="compliance-requirement-text" className={LABEL}>{tr.field_requirement_text ?? 'Kravtekst'}<RequiredMark /></label>
                <p id="compliance-requirement-text-hint" className={HINT}>{tr.field_requirement_text_hint ?? 'Hva kravet sier – gjerne ordrett fra kilden.'}</p>
                <textarea
                    id="compliance-requirement-text"
                    rows={5}
                    required
                    aria-required="true"
                    aria-describedby="compliance-requirement-text-hint"
                    value={form.data.requirement_text}
                    onChange={(event) => form.setData('requirement_text', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.requirement_text && <p className={ERROR}>{form.errors.requirement_text}</p>}
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <div>
                    <label htmlFor="compliance-requirement-owner" className={LABEL}>{tr.field_owner ?? 'Ansvarlig'}<RequiredMark /></label>
                    <select
                        id="compliance-requirement-owner"
                        required
                        aria-required="true"
                        aria-describedby="compliance-requirement-owner-hint"
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tr.choose_owner ?? 'Velg ansvarlig'}</option>
                        {ownerOptions.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                    <p id="compliance-requirement-owner-hint" className={HINT}>
                        {tr.field_owner_hint ?? 'Personen som følger opp kravet. Bare personer med tilgang til Etterlevelse og revisjon kan velges.'}
                    </p>
                    {form.errors.owner_user_id && <p className={ERROR}>{form.errors.owner_user_id}</p>}
                </div>

                <div>
                    <label htmlFor="compliance-requirement-review" className={LABEL}>{tr.field_review ?? 'Revurderingsintervall'}</label>
                    <select
                        id="compliance-requirement-review"
                        aria-describedby="compliance-requirement-review-hint"
                        value={form.data.review_interval_months}
                        onChange={(event) => form.setData('review_interval_months', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{reviewIntervalLabel(null, tr)}</option>
                        {reviewIntervals.map((months) => (
                            <option key={months} value={months}>{reviewIntervalLabel(months, tr)}</option>
                        ))}
                    </select>
                    <p id="compliance-requirement-review-hint" className={HINT}>{tr.field_review_hint ?? 'Hvor ofte kravet skal vurderes på nytt.'}</p>
                    {form.errors.review_interval_months && <p className={ERROR}>{form.errors.review_interval_months}</p>}
                </div>
            </div>

            <p className="text-base text-slate-600">{tr.required_note ?? 'Felt merket med * må fylles ut.'}</p>

            <div className="flex flex-wrap justify-end gap-3">
                {onCancel && (
                    <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                )}
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}
