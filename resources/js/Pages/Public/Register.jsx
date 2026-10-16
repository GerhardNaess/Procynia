import { Link, useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import PublicLayout from '../../Layouts/PublicLayout';
import FormField, { RequiredFieldsNote } from '../../Components/App/FormField';
import { fieldA11y } from '../../Support/formFieldA11y';
import { useFocusFirstError } from '../../Support/focusManagement';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

/*
 * The registration form keeps its own look (the public site's type scale); FormField only takes over
 * the wiring — label for/id, hint and error on aria-describedby, aria-invalid, aria-required and the
 * «*» (A11Y-013, A11Y-121, Fase 6). The border is the field tone (A11Y-019), the hint slate-600 and
 * the error rose-700 (A11Y-016).
 */
const FIELD_LAYOUT = {
    className: 'space-y-2',
    labelClassName: 'text-sm font-medium text-slate-700',
    controlClassName: '',
    hintClassName: 'text-xs leading-6 text-slate-600',
    errorClassName: 'text-sm text-rose-700',
};

const FIELD_CLASS = 'w-full rounded-2xl border border-field-border bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100';

export default function Register() {
    const { translations = {}, publicRegistration = {} } = usePage().props;
    const text = translations.public?.register ?? {};
    const formText = translations.public?.registration?.form ?? {};
    const languageOptions = Array.isArray(publicRegistration.languages) ? publicRegistration.languages : [];
    const nationalityOptions = Array.isArray(publicRegistration.nationalities) ? publicRegistration.nationalities : [];

    const { data, setData, post, processing, errors } = useForm({
        company_name: '',
        nationality_id: '',
        language_id: '',
        owner_name: '',
        owner_email: '',
        password: '',
        privacy_accepted: false,
        terms_accepted: false,
        represents_business: false,
    });

    const a11y = translations.a11y ?? {};
    const formRef = useRef(null);
    // A rejected registration puts the user on the first field that says why (A11Y-013). The ref is
    // the card, so the error summary above the form is the fallback when no field carries the error.
    const focusFirstError = useFocusFirstError(formRef);
    const checkboxA11y = (name) => fieldA11y({ id: name, hasError: Boolean(errors[name]), required: true });

    const submit = (event) => {
        event.preventDefault();
        // aria-disabled rather than disabled while sending: the button keeps focus (A11Y-143).
        if (processing) return;
        post('/registrer', {
            preserveScroll: true,
            onError: focusFirstError,
        });
    };

    const validationMessages = Object.values(errors).filter(Boolean);

    return (
        <PublicLayout title={formText.title ?? text.title ?? 'Opprett konto'}>
            <section className="mx-auto max-w-3xl space-y-4">
                <div className="inline-flex rounded-full border border-indigo-100 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 shadow-sm shadow-indigo-100/50">
                    {text.notice}
                </div>
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">
                    {formText.title ?? text.title}
                </h1>
                <p className="max-w-2xl text-lg leading-8 text-slate-600">
                    {formText.lead ?? text.lead}
                </p>
                <p className="max-w-2xl text-sm leading-7 text-slate-600">
                    {text.notice}
                </p>
            </section>

            <section ref={formRef} className="mt-10 rounded-[2.25rem] border border-slate-200 bg-white p-6 shadow-[0_20px_70px_-45px_rgba(15,23,42,0.45)] sm:p-8">
                <div className="space-y-3">
                    <div className="text-sm font-semibold uppercase tracking-[0.26em] text-slate-500">
                        {formText.business_only ?? 'Kun for virksomheter'}
                    </div>
                    <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                        {formText.title ?? 'Opprett konto'}
                    </h2>
                    <p className="text-base leading-7 text-slate-600">
                        {formText.lead ?? 'Kom i gang med Procynia på få minutter.'}
                    </p>
                </div>

                {validationMessages.length > 0 ? (
                    <div data-form-error-summary className="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm leading-7 text-rose-800">
                        <div className="font-semibold">
                            {formText.error_title ?? translations.public?.registration?.failure ?? 'Registreringen kunne ikke fullføres.'}
                        </div>
                        <p className="mt-1">
                            {formText.error_body ?? 'Noen opplysninger må rettes før kontoen kan opprettes.'}
                        </p>
                    </div>
                ) : null}

                <form className="mt-6 space-y-6" onSubmit={submit}>
                    <RequiredFieldsNote text={a11y.required_fields} className="text-sm text-slate-600" />
                    <div className="grid gap-5">
                        <FormField
                            id="company_name"
                            label={formText.company_name}
                            hint={formText.company_name_help}
                            error={errors.company_name}
                            required
                            {...FIELD_LAYOUT}
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    name="company_name"
                                    type="text"
                                    value={data.company_name}
                                    onChange={(event) => setData('company_name', event.target.value)}
                                    className={FIELD_CLASS}
                                    autoComplete="organization"
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            id="nationality_id"
                            label={formText.nationality_id}
                            error={errors.nationality_id}
                            required
                            {...FIELD_LAYOUT}
                        >
                            {(field) => (
                                <select
                                    {...field}
                                    name="nationality_id"
                                    value={data.nationality_id}
                                    onChange={(event) => setData('nationality_id', event.target.value)}
                                    className={FIELD_CLASS}
                                    required
                                >
                                    <option value="">{formText.nationality_id_help}</option>
                                    {nationalityOptions.map((option) => (
                                        <option key={option.id} value={option.id}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </FormField>

                        <FormField
                            id="language_id"
                            label={formText.language_id}
                            error={errors.language_id}
                            required
                            {...FIELD_LAYOUT}
                        >
                            {(field) => (
                                <select
                                    {...field}
                                    name="language_id"
                                    value={data.language_id}
                                    onChange={(event) => setData('language_id', event.target.value)}
                                    className={FIELD_CLASS}
                                    required
                                >
                                    <option value="">{formText.language_id_help}</option>
                                    {languageOptions.map((option) => (
                                        <option key={option.id} value={option.id}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </FormField>

                        <FormField
                            id="owner_name"
                            label={formText.owner_name}
                            hint={formText.owner_name_help}
                            error={errors.owner_name}
                            required
                            {...FIELD_LAYOUT}
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    name="owner_name"
                                    type="text"
                                    value={data.owner_name}
                                    onChange={(event) => setData('owner_name', event.target.value)}
                                    className={FIELD_CLASS}
                                    autoComplete="name"
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            id="owner_email"
                            label={formText.owner_email}
                            hint={formText.owner_email_help}
                            error={errors.owner_email}
                            required
                            {...FIELD_LAYOUT}
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    name="owner_email"
                                    type="email"
                                    value={data.owner_email}
                                    onChange={(event) => setData('owner_email', event.target.value)}
                                    className={FIELD_CLASS}
                                    autoComplete="email"
                                    inputMode="email"
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            id="password"
                            label={formText.password}
                            hint={formText.password_help}
                            error={errors.password}
                            required
                            {...FIELD_LAYOUT}
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    name="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(event) => setData('password', event.target.value)}
                                    className={FIELD_CLASS}
                                    autoComplete="new-password"
                                    required
                                />
                            )}
                        </FormField>
                    </div>

                    <fieldset aria-labelledby="register-legal-heading" className="rounded-[1.75rem] border border-slate-200 bg-slate-50/70 p-5">
                        <div id="register-legal-heading" className="text-sm font-semibold uppercase tracking-[0.22em] text-slate-600">
                            {formText.legal_section}
                        </div>
                        <div className="mt-4 space-y-4">
                            <div>
                                <label className="flex items-start gap-3 text-sm leading-7 text-slate-700" htmlFor="privacy_accepted">
                                    <input
                                        {...checkboxA11y('privacy_accepted').controlProps}
                                        name="privacy_accepted"
                                        type="checkbox"
                                        checked={data.privacy_accepted}
                                        onChange={(event) => setData('privacy_accepted', event.target.checked)}
                                        className="mt-1 h-4 w-4 rounded border-slate-500 text-blue-600 focus:ring-blue-200"
                                        required
                                    />
                                    <span>
                                        Jeg godtar{' '}
                                        <a href="/personvern?fra=registrer" className="font-medium text-blue-700 underline hover:text-blue-800" onClick={(e) => e.stopPropagation()}>
                                            personvernvilkårene
                                        </a>
                                        .
                                        <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>
                                    </span>
                                </label>
                                {errors.privacy_accepted ? (
                                    <p id={checkboxA11y('privacy_accepted').errorId} className="text-sm text-rose-700">{errors.privacy_accepted}</p>
                                ) : null}
                            </div>

                            <div>
                                <label className="flex items-start gap-3 text-sm leading-7 text-slate-700" htmlFor="terms_accepted">
                                    <input
                                        {...checkboxA11y('terms_accepted').controlProps}
                                        name="terms_accepted"
                                        type="checkbox"
                                        checked={data.terms_accepted}
                                        onChange={(event) => setData('terms_accepted', event.target.checked)}
                                        className="mt-1 h-4 w-4 rounded border-slate-500 text-blue-600 focus:ring-blue-200"
                                        required
                                    />
                                    <span>
                                        Jeg godtar{' '}
                                        <a href="/betingelser?fra=registrer" className="font-medium text-blue-700 underline hover:text-blue-800" onClick={(e) => e.stopPropagation()}>
                                            betingelsene
                                        </a>
                                        .
                                        <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>
                                    </span>
                                </label>
                                {errors.terms_accepted ? (
                                    <p id={checkboxA11y('terms_accepted').errorId} className="text-sm text-rose-700">{errors.terms_accepted}</p>
                                ) : null}
                            </div>

                            <div>
                                <label className="flex items-start gap-3 text-sm leading-7 text-slate-700" htmlFor="represents_business">
                                    <input
                                        {...checkboxA11y('represents_business').controlProps}
                                        name="represents_business"
                                        type="checkbox"
                                        checked={data.represents_business}
                                        onChange={(event) => setData('represents_business', event.target.checked)}
                                        className="mt-1 h-4 w-4 rounded border-slate-500 text-blue-600 focus:ring-blue-200"
                                        required
                                    />
                                    <span>
                                        {formText.represents_business}
                                        <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>
                                    </span>
                                </label>
                                {errors.represents_business ? (
                                    <p id={checkboxA11y('represents_business').errorId} className="text-sm text-rose-700">{errors.represents_business}</p>
                                ) : null}
                            </div>
                        </div>
                    </fieldset>

                    <button
                        type="submit"
                        aria-disabled={processing || undefined}
                        className={classNames(
                            'inline-flex w-full items-center justify-center rounded-2xl px-4 py-3 text-sm font-semibold text-white transition',
                            processing ? 'cursor-not-allowed bg-slate-400' : 'bg-blue-600 hover:bg-blue-700',
                        )}
                    >
                        {processing ? (translations.common?.loading ?? 'Laster...') : formText.submit}
                    </button>

                    <div className="text-sm leading-7 text-slate-600">
                        {formText.already_have_account}{' '}
                        <Link href="/login" className="font-medium text-slate-950 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-950">
                            {formText.sign_in}
                        </Link>
                        .
                    </div>
                </form>
            </section>
        </PublicLayout>
    );
}
