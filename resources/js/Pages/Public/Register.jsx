import { Link, useForm, usePage } from '@inertiajs/react';
import PublicLayout from '../../Layouts/PublicLayout';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function fieldError(errors, field) {
    return errors?.[field] ?? null;
}

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

    const submit = (event) => {
        event.preventDefault();
        post('/registrer', {
            preserveScroll: true,
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
                <p className="max-w-2xl text-sm leading-7 text-slate-500">
                    {text.notice}
                </p>
            </section>

            <section className="mt-10 rounded-[2.25rem] border border-slate-200 bg-white p-6 shadow-[0_20px_70px_-45px_rgba(15,23,42,0.45)] sm:p-8">
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
                    <div className="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm leading-7 text-rose-800">
                        <div className="font-semibold">
                            {formText.error_title ?? translations.public?.registration?.failure ?? 'Registreringen kunne ikke fullføres.'}
                        </div>
                        <p className="mt-1">
                            {formText.error_body ?? 'Noen opplysninger må rettes før kontoen kan opprettes.'}
                        </p>
                    </div>
                ) : null}

                <form className="mt-6 space-y-6" onSubmit={submit}>
                    <div className="grid gap-5">
                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="company_name">
                                {formText.company_name}
                            </label>
                            <input
                                id="company_name"
                                name="company_name"
                                type="text"
                                value={data.company_name}
                                onChange={(event) => setData('company_name', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100"
                                autoComplete="organization"
                                required
                            />
                            <p className="text-xs leading-6 text-slate-500">{formText.company_name_help}</p>
                            {fieldError(errors, 'company_name') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'company_name')}</p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="nationality_id">
                                {formText.nationality_id}
                            </label>
                            <select
                                id="nationality_id"
                                name="nationality_id"
                                value={data.nationality_id}
                                onChange={(event) => setData('nationality_id', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100"
                                required
                            >
                                <option value="">{formText.nationality_id_help}</option>
                                {nationalityOptions.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            {fieldError(errors, 'nationality_id') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'nationality_id')}</p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="language_id">
                                {formText.language_id}
                            </label>
                            <select
                                id="language_id"
                                name="language_id"
                                value={data.language_id}
                                onChange={(event) => setData('language_id', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100"
                                required
                            >
                                <option value="">{formText.language_id_help}</option>
                                {languageOptions.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            {fieldError(errors, 'language_id') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'language_id')}</p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="owner_name">
                                {formText.owner_name}
                            </label>
                            <input
                                id="owner_name"
                                name="owner_name"
                                type="text"
                                value={data.owner_name}
                                onChange={(event) => setData('owner_name', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100"
                                autoComplete="name"
                                required
                            />
                            <p className="text-xs leading-6 text-slate-500">{formText.owner_name_help}</p>
                            {fieldError(errors, 'owner_name') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'owner_name')}</p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="owner_email">
                                {formText.owner_email}
                            </label>
                            <input
                                id="owner_email"
                                name="owner_email"
                                type="email"
                                value={data.owner_email}
                                onChange={(event) => setData('owner_email', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100"
                                autoComplete="email"
                                inputMode="email"
                                required
                            />
                            <p className="text-xs leading-6 text-slate-500">{formText.owner_email_help}</p>
                            {fieldError(errors, 'owner_email') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'owner_email')}</p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="password">
                                {formText.password}
                            </label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                value={data.password}
                                onChange={(event) => setData('password', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100"
                                autoComplete="new-password"
                                required
                            />
                            <p className="text-xs leading-6 text-slate-500">{formText.password_help}</p>
                            {fieldError(errors, 'password') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'password')}</p>
                            ) : null}
                        </div>
                    </div>

                    <div className="rounded-[1.75rem] border border-slate-200 bg-slate-50/70 p-5">
                        <div className="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">
                            {formText.legal_section}
                        </div>
                        <div className="mt-4 space-y-4">
                            <label className="flex items-start gap-3 text-sm leading-7 text-slate-700" htmlFor="privacy_accepted">
                                <input
                                    id="privacy_accepted"
                                    name="privacy_accepted"
                                    type="checkbox"
                                    checked={data.privacy_accepted}
                                    onChange={(event) => setData('privacy_accepted', event.target.checked)}
                                    className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-200"
                                    required
                                />
                                <span>
                                    Jeg godtar{' '}
                                    <a href="/personvern?fra=registrer" className="font-medium text-blue-600 underline hover:text-blue-700" onClick={(e) => e.stopPropagation()}>
                                        personvernvilkårene
                                    </a>
                                    .
                                </span>
                            </label>
                            {fieldError(errors, 'privacy_accepted') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'privacy_accepted')}</p>
                            ) : null}

                            <label className="flex items-start gap-3 text-sm leading-7 text-slate-700" htmlFor="terms_accepted">
                                <input
                                    id="terms_accepted"
                                    name="terms_accepted"
                                    type="checkbox"
                                    checked={data.terms_accepted}
                                    onChange={(event) => setData('terms_accepted', event.target.checked)}
                                    className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-200"
                                    required
                                />
                                <span>
                                    Jeg godtar{' '}
                                    <a href="/betingelser?fra=registrer" className="font-medium text-blue-600 underline hover:text-blue-700" onClick={(e) => e.stopPropagation()}>
                                        betingelsene
                                    </a>
                                    .
                                </span>
                            </label>
                            {fieldError(errors, 'terms_accepted') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'terms_accepted')}</p>
                            ) : null}

                            <label className="flex items-start gap-3 text-sm leading-7 text-slate-700" htmlFor="represents_business">
                                <input
                                    id="represents_business"
                                    name="represents_business"
                                    type="checkbox"
                                    checked={data.represents_business}
                                    onChange={(event) => setData('represents_business', event.target.checked)}
                                    className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-200"
                                    required
                                />
                                <span>{formText.represents_business}</span>
                            </label>
                            {fieldError(errors, 'represents_business') ? (
                                <p className="text-sm text-rose-600">{fieldError(errors, 'represents_business')}</p>
                            ) : null}
                        </div>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
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
