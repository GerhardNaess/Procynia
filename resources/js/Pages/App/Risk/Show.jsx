import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RiskForm from './RiskForm';
import { RISK_STATUS_TONES } from './riskStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * One risk. Edit and delete are offered only when the server said this person may do them to a
 * risk in this area; the controller refuses them otherwise.
 */
export default function RiskShow() {
    const {
        translations = {},
        risk,
        statuses = [],
        permissions = {},
        area_options: areaOptions = [],
        owner_options: ownerOptions = [],
    } = usePage().props;

    const tr = translations?.risk ?? {};
    const statusLabels = tr.statuses ?? {};
    const [editing, setEditing] = useState(false);

    const form = useForm({
        title: risk.title ?? '',
        description: risk.description ?? '',
        risk_access_area_id: String(risk.risk_access_area_id ?? ''),
        owner_user_id: risk.owner_user_id ? String(risk.owner_user_id) : '',
        status: risk.status,
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`/app/risk/risks/${risk.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const destroy = () => {
        if (! window.confirm(tr.delete_confirm ?? 'Slett risikoen? Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/risk/risks/${risk.id}`);
    };

    return (
        <CustomerAppLayout title={risk.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href="/app/risk" className="text-base font-semibold text-violet-700 hover:text-violet-900">
                    ← {tr.back ?? 'Til risikoregisteret'}
                </Link>

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{risk.title}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge tone={RISK_STATUS_TONES[risk.status] ?? 'slate'}>
                                {statusLabels[risk.status] ?? risk.status}
                            </StatusBadge>
                            <StatusBadge tone="slate">{risk.area_name}</StatusBadge>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {permissions.can_edit && ! editing && (
                            <button type="button" onClick={() => setEditing(true)} className={SECONDARY_ACTION}>
                                {tr.edit ?? 'Rediger'}
                            </button>
                        )}
                        {permissions.can_delete && (
                            <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>
                                {tr.delete ?? 'Slett risiko'}
                            </button>
                        )}
                    </div>
                </header>

                {editing ? (
                    <section className={CARD}>
                        <RiskForm
                            form={form}
                            onSubmit={submit}
                            onCancel={() => { setEditing(false); form.reset(); form.clearErrors(); }}
                            areaOptions={areaOptions}
                            ownerOptions={ownerOptions}
                            statuses={statuses}
                            statusLabels={statusLabels}
                            tr={tr}
                        />
                    </section>
                ) : (
                    <section className={CARD}>
                        <h2 className="text-lg font-semibold text-slate-950">{tr.details ?? 'Detaljer'}</h2>
                        <p className="mt-3 whitespace-pre-line text-base leading-6 text-slate-700">
                            {risk.description || (tr.no_description ?? 'Ingen beskrivelse.')}
                        </p>
                        <dl className="mt-6 grid gap-4 sm:grid-cols-3">
                            <div>
                                <dt className="text-sm font-semibold text-slate-600">{tr.field_owner ?? 'Risikoeier'}</dt>
                                <dd className="mt-1 text-base text-slate-900">{risk.owner_name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-sm font-semibold text-slate-600">{tr.field_area ?? 'Tilgangsområde'}</dt>
                                <dd className="mt-1 text-base text-slate-900">{risk.area_name}</dd>
                            </div>
                            <div>
                                <dt className="text-sm font-semibold text-slate-600">{tr.updated ?? 'Sist endret'}</dt>
                                <dd className="mt-1 text-base text-slate-900">
                                    {risk.updated_at ? new Date(risk.updated_at).toLocaleString('nb-NO') : '—'}
                                </dd>
                            </div>
                        </dl>
                    </section>
                )}
            </div>
        </CustomerAppLayout>
    );
}
