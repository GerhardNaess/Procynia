function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

const primarySteps = [
    { key: 'discovered', label: 'Registrert' },
    { key: 'qualifying', label: 'Kvalifiseres' },
    { key: 'go_no_go', label: 'Go / No-Go' },
    { key: 'in_progress', label: 'Under arbeid' },
    { key: 'submitted', label: 'Sendt' },
    { key: 'negotiation', label: 'Forhandling' },
];

function primaryStepIndex(currentStatus) {
    return primarySteps.findIndex((step) => step.key === currentStatus);
}

function activePrimaryStepClassName(stepKey) {
    switch (stepKey) {
        case 'qualifying':
            return 'border-sky-300 bg-sky-50';
        case 'go_no_go':
            return 'border-amber-300 bg-amber-50';
        case 'in_progress':
            return 'border-emerald-300 bg-emerald-50';
        case 'submitted':
            return 'border-blue-300 bg-blue-50';
        case 'negotiation':
            return 'border-violet-300 bg-violet-50';
        default:
            return 'border-slate-300 bg-slate-100';
    }
}

function activePrimaryStepBarClassName(stepKey) {
    switch (stepKey) {
        case 'qualifying':
            return 'bg-sky-600';
        case 'go_no_go':
            return 'bg-amber-500';
        case 'in_progress':
            return 'bg-emerald-500';
        case 'submitted':
            return 'bg-blue-600';
        case 'negotiation':
            return 'bg-violet-600';
        default:
            return 'bg-slate-600';
    }
}

export default function BidStatusPipeline({ currentStatus }) {
    const currentPrimaryStepIndex = primaryStepIndex(currentStatus);

    return (
        <div className="rounded-[24px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            <div className="grid gap-3 md:grid-cols-6">
                {primarySteps.map((step, index) => {
                    const isActive = currentStatus === step.key;
                    const isCompleted = currentPrimaryStepIndex >= 0 && index < currentPrimaryStepIndex;

                    return (
                        <div
                            key={step.key}
                            aria-current={isActive ? 'step' : undefined}
                            className={classNames(
                                'rounded-2xl border px-4 py-3 transition',
                                isActive
                                    ? activePrimaryStepClassName(step.key)
                                    : isCompleted
                                        ? 'border-emerald-200 bg-emerald-50'
                                        : 'border-slate-200 bg-slate-50',
                            )}
                        >
                            <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                                Steg {index + 1}
                            </div>
                            <div className="mt-2 text-sm font-semibold text-slate-950">{step.label}</div>
                            <div
                                className={classNames(
                                    'mt-3 h-1.5 rounded-full',
                                    isActive
                                        ? activePrimaryStepBarClassName(step.key)
                                        : isCompleted
                                            ? 'bg-emerald-500'
                                            : 'bg-slate-200',
                                )}
                            />
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
