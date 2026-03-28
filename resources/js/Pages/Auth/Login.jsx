import { Head, useForm, usePage } from '@inertiajs/react';

export default function Login() {
    const { appName, translations } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: true,
    });

    const submit = (event) => {
        event.preventDefault();
        post('/login');
    };

    return (
        <>
            <Head title={`${translations.auth.title} · ${appName}`} />
            <div className="flex min-h-screen items-center justify-center bg-slate-950 px-6 py-16">
                <div className="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl shadow-slate-950/25">
                    <div className="mb-8 space-y-2">
                        <div className="text-sm font-medium uppercase tracking-[0.2em] text-slate-500">{appName}</div>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{translations.auth.title}</h1>
                        <p className="text-sm text-slate-600">{translations.auth.subtitle}</p>
                    </div>

                    <form className="space-y-5" onSubmit={submit}>
                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="email">
                                {translations.auth.email}
                            </label>
                            <input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-950 outline-none transition focus:border-slate-400"
                                autoComplete="email"
                                required
                            />
                            {errors.email ? <p className="text-sm text-rose-600">{errors.email}</p> : null}
                        </div>

                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700" htmlFor="password">
                                {translations.auth.password}
                            </label>
                            <input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(event) => setData('password', event.target.value)}
                                className="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-950 outline-none transition focus:border-slate-400"
                                autoComplete="current-password"
                                required
                            />
                            {errors.password ? <p className="text-sm text-rose-600">{errors.password}</p> : null}
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
                </div>
            </div>
        </>
    );
}
