import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { fieldA11y } from '../../Support/formFieldA11y';
import { focusFirstInvalidField } from '../../Support/focusManagement';

export default function Login() {
    const { appName, translations, authOptions = {} } = usePage().props;
    // Defaults keep the page working if a deployment predates the Entra props.
    const localLoginEnabled = authOptions.localLoginEnabled ?? true;
    const entraEnabled = authOptions.entraEnabled ?? false;
    const entraUrl = authOptions.entraUrl ?? '/login/entra';
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: true,
    });

    const submit = (event) => {
        event.preventDefault();
        post('/login');
    };

    // A rejected sign-in puts the user on the field that says why; its error is read with it. Run when
    // the errors arrive and have rendered (the field already carries aria-invalid) — not from onError,
    // which runs before that render. Errors only ever come from a submit, so this never fires on its own.
    useEffect(() => {
        if (errors.email || errors.password) {
            focusFirstInvalidField(document.getElementById('login-form'));
        }
    }, [errors]);

    // Each field is tied to its own error (aria-describedby + aria-invalid), so the message is heard
    // on the field instead of being a loose line under it (A11Y-013, A11Y-051).
    const email = fieldA11y({ id: 'email', hasError: Boolean(errors.email) });
    const password = fieldA11y({ id: 'password', hasError: Boolean(errors.password) });

    return (
        <>
            <Head title={`${translations.auth.title} · ${appName}`} />
            <main className="flex min-h-screen items-center justify-center bg-slate-950 px-4 py-16 sm:px-6">
                <div className="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl shadow-slate-950/25">
                    <div className="mb-8 space-y-2">
                        <div className="text-sm font-medium uppercase tracking-[0.2em] text-slate-500">{appName}</div>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{translations.auth.title}</h1>
                        <p className="text-sm text-slate-600">{translations.auth.subtitle}</p>
                    </div>

                    {/* Not tied to a field — the Microsoft sign-in failed — so it is announced itself. */}
                    {errors.entra ? (
                        <p role="alert" className="mb-5 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{errors.entra}</p>
                    ) : null}

                    {entraEnabled ? (
                        <a
                            href={entraUrl}
                            className="flex w-full items-center justify-center gap-3 rounded-2xl border border-slate-300 px-4 py-3 font-medium text-slate-900 transition hover:bg-slate-50"
                        >
                            <svg aria-hidden="true" viewBox="0 0 23 23" className="h-5 w-5">
                                <path fill="#f25022" d="M0 0h11v11H0z" />
                                <path fill="#7fba00" d="M12 0h11v11H12z" />
                                <path fill="#00a4ef" d="M0 12h11v11H0z" />
                                <path fill="#ffb900" d="M12 12h11v11H12z" />
                            </svg>
                            {translations.auth.entra_sign_in ?? 'Logg inn med Microsoft'}
                        </a>
                    ) : null}

                    {entraEnabled && localLoginEnabled ? (
                        <div className="my-6 flex items-center gap-4">
                            <span className="h-px flex-1 bg-slate-200" />
                            <span className="text-xs uppercase tracking-widest text-slate-600">
                                {translations.auth.or ?? 'eller'}
                            </span>
                            <span className="h-px flex-1 bg-slate-200" />
                        </div>
                    ) : null}

                    {localLoginEnabled ? (
                    <form id="login-form" className="space-y-5" onSubmit={submit}>
                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="email">
                                {translations.auth.email}
                            </label>
                            <input
                                {...email.controlProps}
                                type="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-950 outline-none transition focus:border-slate-400"
                                autoComplete="email"
                                required
                            />
                            {errors.email ? <p id={email.errorId} className="text-sm text-rose-700">{errors.email}</p> : null}
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="password">
                                {translations.auth.password}
                            </label>
                            <input
                                {...password.controlProps}
                                type="password"
                                value={data.password}
                                onChange={(event) => setData('password', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-950 outline-none transition focus:border-slate-400"
                                autoComplete="current-password"
                                required
                            />
                            {errors.password ? <p id={password.errorId} className="text-sm text-rose-700">{errors.password}</p> : null}
                        </div>

                        <label className="flex items-center gap-3 text-sm text-slate-600">
                            <input
                                type="checkbox"
                                checked={data.remember}
                                onChange={(event) => setData('remember', event.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                            />
                            <span>{translations.auth.remember}</span>
                        </label>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-2xl bg-slate-950 px-4 py-3 font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                        >
                            {translations.auth.sign_in}
                        </button>
                    </form>
                    ) : null}
                </div>
            </main>
        </>
    );
}
