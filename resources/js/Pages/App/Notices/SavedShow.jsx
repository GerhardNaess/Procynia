import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import BidStatusPipeline from '../../../Components/App/BidStatusPipeline';

function formatDate(value, locale, options = {}) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...options,
    }).format(new Date(value));
}

function dateInputValue(value) {
    if (!value) {
        return '';
    }

    return String(value).slice(0, 10);
}

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function actionButtonClassName(tone, status = null) {
    if (status === 'no_go') {
        return 'border-amber-200 bg-amber-50 text-amber-800 hover:border-amber-300 hover:bg-amber-100';
    }

    if (tone === 'danger') {
        return 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100';
    }

    if (tone === 'success') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100';
    }

    if (tone === 'secondary') {
        return 'border-slate-200 bg-slate-100 text-slate-700 hover:border-slate-300 hover:bg-slate-200';
    }

    return 'border-violet-200 bg-violet-50 text-violet-700 hover:border-violet-300 hover:bg-violet-100';
}

function bidStatusBadgeClassName(status) {
    switch (status) {
        case 'qualifying':
            return 'bg-sky-100 text-sky-700 ring-sky-200';
        case 'go_no_go':
            return 'bg-amber-100 text-amber-800 ring-amber-200';
        case 'in_progress':
            return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
        case 'submitted':
            return 'bg-blue-100 text-blue-700 ring-blue-200';
        case 'negotiation':
            return 'bg-violet-100 text-violet-700 ring-violet-200';
        case 'won':
            return 'bg-emerald-100 text-emerald-700 ring-emerald-200';
        case 'lost':
        case 'withdrawn':
            return 'bg-rose-100 text-rose-700 ring-rose-200';
        case 'no_go':
            return 'bg-amber-200 text-amber-900 ring-amber-300';
        case 'archived':
            return 'bg-slate-200 text-slate-700 ring-slate-300';
        default:
            return 'bg-slate-100 text-slate-700 ring-slate-200';
    }
}

function bidRoleLabel(role) {
    switch (role) {
        case 'bid_manager':
            return 'Bid-manager';
        case 'viewer':
            return 'Lesetilgang';
        case 'contributor':
        default:
            return 'Bid-bidragsyter';
    }
}

function accessRoleLabel(role) {
    switch (role) {
        case 'viewer':
            return 'Lesetilgang';
        case 'contributor':
        default:
            return 'Bid-bidragsyter';
    }
}

function statusActionLabel(status) {
    switch (status) {
        case 'qualifying':
            return 'Gå til kvalifisering';
        case 'go_no_go':
            return 'Gå til Go / No-Go';
        case 'in_progress':
            return 'Gå til under arbeid';
        case 'submitted':
            return 'Gå til sendt';
        case 'negotiation':
            return 'Gå til forhandling';
        case 'won':
            return 'Marker som vunnet';
        case 'lost':
            return 'Marker som tapt';
        case 'no_go':
            return 'Sett som No-Go';
        case 'withdrawn':
            return 'Trekk saken';
        case 'archived':
            return 'Arkiver saken';
        default:
            return status;
    }
}

function primaryActionStatus(currentStatus) {
    switch (currentStatus) {
        case 'discovered':
            return 'qualifying';
        case 'qualifying':
            return 'go_no_go';
        case 'go_no_go':
            return 'in_progress';
        case 'in_progress':
            return 'submitted';
        case 'submitted':
            return 'negotiation';
        case 'won':
        case 'lost':
        case 'no_go':
        case 'withdrawn':
            return 'archived';
        default:
            return null;
    }
}

function filterVisibleStatusActions(currentStatus, actions) {
    if (currentStatus === 'discovered') {
        return actions.filter((action) => action.status !== 'no_go');
    }

    return actions;
}

function lifecycleGuidance(status, isArchived) {
    if (isArchived) {
        return {
            phaseTitle: 'Arkivert sak',
            description: 'Saken ligger utenfor aktiv bid-flyt og er kun tilgjengelig som historikk.',
            closureRule: 'Ingen videre statusendringer er tilgjengelige her.',
            nextStepDescription: 'Saken er ferdig behandlet.',
        };
    }

    switch (status) {
        case 'discovered':
            return {
                phaseTitle: 'Tidlig screening',
                description: 'Saken er registrert, men ikke kvalifisert ennå.',
                closureRule: 'No-Go vises ikke i dette steget. Første operative handling er å starte kvalifisering.',
                nextStepDescription: 'Start kvalifisering av saken.',
            };
        case 'qualifying':
            return {
                phaseTitle: 'Innledende vurdering pågår',
                description: 'Vurder relevans, kapasitet og om saken bør løftes videre til Go / No-Go.',
                closureRule: 'Her kan saken enten løftes videre eller avsluttes som No-Go.',
                nextStepDescription: 'Gå videre til Go / No-Go-vurdering.',
            };
        case 'go_no_go':
            return {
                phaseTitle: 'Go / No-Go-vurdering',
                description: 'Dette er beslutningspunktet før aktivt tilbudsarbeid starter.',
                closureRule: 'No-Go er fortsatt gyldig her. Når saken flyttes videre, overtar Trukket som senere avslutning.',
                nextStepDescription: 'Flytt saken til under arbeid når beslutningen er tatt.',
            };
        case 'in_progress':
            return {
                phaseTitle: 'Aktivt tilbudsarbeid',
                description: 'Teamet jobber nå aktivt med leveranse, innhold og koordinering.',
                closureRule: 'No-Go gjelder ikke lenger. Trukket er riktig avslutning dersom saken stoppes nå.',
                nextStepDescription: 'Flytt saken til sendt når tilbudet er levert.',
            };
        case 'submitted':
            return {
                phaseTitle: 'Tilbudet er levert',
                description: 'Saken kan nå gå videre til forhandling eller avsluttes med et utfall.',
                closureRule: 'Trukket er gyldig her. No-Go er ikke lenger en tilgjengelig avslutning.',
                nextStepDescription: 'Flytt saken til forhandling når dialogen etter levering starter.',
            };
        case 'negotiation':
            return {
                phaseTitle: 'Forhandling pågår',
                description: 'Det pågår dialog, avklaringer eller justeringer etter innsending.',
                closureRule: 'Trukket er fortsatt gyldig her fordi arbeidet allerede har startet.',
                nextStepDescription: 'Velg utfall for saken.',
            };
        case 'no_go':
            return {
                phaseTitle: 'Tidlig avsluttet',
                description: 'Saken ble avsluttet som No-Go før aktivt tilbudsarbeid startet.',
                closureRule: 'No-Go brukes for tidlig stopp i kvalifisering eller Go / No-Go.',
                nextStepDescription: 'Saken kan arkiveres.',
            };
        case 'withdrawn':
            return {
                phaseTitle: 'Senere avsluttet',
                description: 'Saken ble trukket etter at arbeidet hadde startet.',
                closureRule: 'Trukket brukes etter Go / No-Go og er derfor forskjellig fra No-Go.',
                nextStepDescription: 'Saken kan arkiveres.',
            };
        case 'won':
            return {
                phaseTitle: 'Avsluttet som vunnet',
                description: 'Tilbudsprosessen er avsluttet med positivt utfall.',
                closureRule: 'Dette er et endelig utfall, ikke en tidlig avslutning.',
                nextStepDescription: 'Saken kan arkiveres.',
            };
        case 'lost':
            return {
                phaseTitle: 'Avsluttet som tapt',
                description: 'Tilbudsprosessen er avsluttet med negativt utfall.',
                closureRule: 'Dette er et endelig utfall, ikke en tidlig avslutning.',
                nextStepDescription: 'Saken kan arkiveres.',
            };
        default:
            return {
                phaseTitle: 'Standard bid-flyt',
                description: 'Saken følger den etablerte faseflyten for bid-arbeid.',
                closureRule: 'Backend avgjør hvilke overganger som er gyldige fra dette punktet.',
                nextStepDescription: 'Ingen videre handling er tilgjengelig akkurat nå.',
            };
    }
}

function closureActionGuidance(actionStatus) {
    switch (actionStatus) {
        case 'no_go':
            return {
                className: 'border-amber-200 bg-amber-50 text-amber-800',
                text: 'Bruk No-Go kun for en tidlig avslutning før aktivt tilbudsarbeid har startet.',
            };
        case 'withdrawn':
            return {
                className: 'border-rose-200 bg-rose-50 text-rose-800',
                text: 'Bruk Trukket kun etter at saken har gått videre fra Go / No-Go og arbeidet har startet.',
            };
        default:
            return null;
    }
}

function deadlineStateLabel(notice, locale) {
    if (!notice.next_deadline_at) {
        return notice.deadline ? formatDate(notice.deadline, locale) : 'Ikke registrert';
    }

    const prefix = notice.next_deadline_type ? `${notice.next_deadline_type} ` : '';

    return `${prefix}${formatDate(notice.next_deadline_at, locale)}`;
}

function ActionAccordionSection({ title, summary, hint = null, isOpen, onToggle, children }) {
    return (
        <section className={classNames(
            'overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 transition',
            isOpen ? 'shadow-[0_6px_20px_rgba(15,23,42,0.04)]' : '',
        )}>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={isOpen}
                className="flex w-full items-start justify-between gap-4 px-4 py-4 text-left transition hover:bg-slate-100/80"
            >
                <div className="min-w-0 space-y-1">
                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                        {title}
                    </div>
                    <div className="truncate text-sm font-semibold text-slate-950">
                        {summary}
                    </div>
                    {hint ? (
                        <div className="text-xs leading-5 text-slate-500">
                            {hint}
                        </div>
                    ) : null}
                </div>

                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-sm font-semibold text-slate-600">
                    {isOpen ? '−' : '+'}
                </div>
            </button>

            {isOpen ? (
                <div className="border-t border-slate-200 bg-white px-4 py-4">
                    {children}
                </div>
            ) : null}
        </section>
    );
}

function PhaseCommentCard({ comment, locale }) {
    return (
        <article className="rounded-2xl border border-slate-200 bg-white px-4 py-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="text-sm font-semibold text-slate-950">
                            {comment.user?.name || 'Ukjent bruker'}
                        </div>
                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700">
                            {comment.user?.bid_role_label || 'Bid-bidragsyter'}
                        </span>
                    </div>
                    <div className="text-xs text-slate-500">
                        {comment.user?.email || '—'}
                        {' · '}
                        {formatDate(comment.created_at, locale, { hour: '2-digit', minute: '2-digit' })}
                    </div>
                </div>

                <span className="rounded-full bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700">
                    {comment.phase_status_label}
                </span>
            </div>

            <p className="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">
                {comment.comment}
            </p>
        </article>
    );
}

export default function SavedNoticeShow({ notice }) {
    const page = usePage();
    const { auth, errors = {} } = page.props;
    const locale = document.documentElement.lang || 'no-NO';
    const submissionForm = useForm({});
    const statusForm = useForm({
        status: '',
        bid_closure_reason: '',
        bid_closure_note: '',
    });
    const opportunityOwnerForm = useForm({
        opportunity_owner_user_id: notice.opportunity_owner?.id ? String(notice.opportunity_owner.id) : '',
    });
    const bidManagerForm = useForm({
        bid_manager_user_id: notice.bid_manager?.id ? String(notice.bid_manager.id) : '',
    });
    const deadlineForm = useForm({
        questions_deadline_at: '',
        questions_rfi_deadline_at: '',
        rfi_submission_deadline_at: '',
        questions_rfp_deadline_at: '',
        rfp_submission_deadline_at: '',
        award_date_at: '',
    });
    const phaseCommentForm = useForm({
        comment: '',
    });
    const caseAccessForm = useForm({
        user_id: '',
        access_role: notice.actions?.case_access?.access_role_options?.[0]?.value ?? 'contributor',
    });
    const [isEditingDeadlines, setIsEditingDeadlines] = useState(false);
    const [openClosureStatus, setOpenClosureStatus] = useState(null);
    const [openActionSection, setOpenActionSection] = useState('decision');
    const [openCommentPhases, setOpenCommentPhases] = useState({});
    const [isStatusActionProcessing, setIsStatusActionProcessing] = useState(false);
    const shouldShowSubmissions = notice.submissions.length > 0
        || notice.bid_status === 'submitted'
        || notice.bid_status === 'negotiation';
    const statusActions = filterVisibleStatusActions(notice.bid_status, notice.actions?.status_actions ?? []);
    const closureReasonOptions = notice.actions?.closure_reasons ?? [];
    const opportunityOwnerOptions = notice.actions?.opportunity_owner_options ?? [];
    const bidManagerOptions = notice.actions?.bid_manager_options ?? [];
    const caseAccess = notice.actions?.case_access ?? {};
    const caseAccessUserOptions = caseAccess.user_options ?? [];
    const caseAccessRoleOptions = caseAccess.access_role_options ?? [];
    const caseAccessEntries = caseAccess.accesses ?? [];
    const caseAccessSummary = caseAccessEntries.length > 0
        ? `${caseAccessEntries.length} aktive brukere`
        : 'Ingen aktive';
    const canManageCaseAccess = caseAccess.can_manage
        ?? (
            auth?.user?.id
            && (
                String(auth.user.id) === String(notice.bid_manager?.id)
                || String(auth.user.id) === String(notice.opportunity_owner?.id)
            )
        );
    const activeClosureAction = statusActions.find((action) => action.status === openClosureStatus) ?? null;
    const noStatusActionsMessage = notice.archived_at
        ? 'Saken ligger i historikk og kan ikke endres videre her.'
        : 'Ingen flere handlinger er tilgjengelige for denne saken.';
    const currentUserBidRoleLabel = bidRoleLabel(auth?.user?.bid_role);
    const currentOpportunityOwnerId = notice.opportunity_owner?.id ? String(notice.opportunity_owner.id) : '';
    const currentBidManagerId = notice.bid_manager?.id ? String(notice.bid_manager.id) : '';
    const isOpportunityOwnerDirty = opportunityOwnerForm.data.opportunity_owner_user_id !== currentOpportunityOwnerId;
    const isBidManagerDirty = bidManagerForm.data.bid_manager_user_id !== currentBidManagerId;
    const isCaseAccessDirty = caseAccessForm.data.user_id !== '';
    const isDeadlineDirty = deadlineForm.isDirty;
    const csrfToken = typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
        : '';
    const primaryAction = statusActions.find((action) => action.status === primaryActionStatus(notice.bid_status)) ?? null;
    const secondaryActions = statusActions.filter((action) => action.status !== primaryAction?.status);
    const guidance = lifecycleGuidance(notice.bid_status, Boolean(notice.archived_at));
    const commentPhaseOptions = [
        { status: 'qualifying', number: '2', label: 'Kvalifiseres' },
        { status: 'go_no_go', number: '3', label: 'Go / No-Go' },
        { status: 'in_progress', number: '4', label: 'Under arbeid' },
        { status: 'negotiation', number: '6', label: 'Forhandling' },
    ].map((option) => ({
        ...option,
        guidance: lifecycleGuidance(option.status, Boolean(notice.archived_at)),
    }));
    const activeCommentPhaseOption = commentPhaseOptions.find((option) => option.status === notice.bid_status) ?? null;
    const phaseCommentEntries = notice.phase_comments?.comments ?? [];
    const phaseCommentGroups = phaseCommentEntries.reduce((groups, comment) => {
        const phase = comment.phase_status;

        if (!groups[phase]) {
            groups[phase] = [];
        }

        groups[phase].push(comment);

        return groups;
    }, {});
    const activePhaseCommentEntries = phaseCommentGroups[notice.bid_status] ?? [];
    const phaseCommentStoreUrl = notice.phase_comments?.store_url ?? null;
    const canCommentOnCase = Boolean(notice.phase_comments?.can_comment);
    const deadlineSummary = deadlineStateLabel(notice, locale);
    const bidManagerSummary = notice.bid_manager?.name || 'Ikke satt';
    const opportunityOwnerSummary = notice.opportunity_owner?.name || 'Ikke satt';
    const administrationSummary = notice.archived_at ? 'Saken er arkivert' : 'Sekundære handlinger';
    const nextDecisionSummary = primaryAction ? statusActionLabel(primaryAction.status) : noStatusActionsMessage;
    const activeCommentPhaseLabel = activeCommentPhaseOption
        ? `${activeCommentPhaseOption.number} ${activeCommentPhaseOption.label}`
        : guidance.phaseTitle;

    useEffect(() => {
        opportunityOwnerForm.setData('opportunity_owner_user_id', currentOpportunityOwnerId);
        opportunityOwnerForm.clearErrors();
    }, [currentOpportunityOwnerId]);

    useEffect(() => {
        bidManagerForm.setData('bid_manager_user_id', currentBidManagerId);
        bidManagerForm.clearErrors();
    }, [currentBidManagerId]);

    const grantCaseAccess = (event) => {
        event.preventDefault();

        if (!caseAccess.store_url) {
            return;
        }

        caseAccessForm.clearErrors();
        caseAccessForm.post(caseAccess.store_url, {
            preserveScroll: true,
            onSuccess: () => {
                caseAccessForm.reset('user_id');
                caseAccessForm.setData('access_role', caseAccessRoleOptions[0]?.value ?? 'contributor');
                caseAccessForm.clearErrors();
            },
        });
    };

    const openDeadlineEditor = () => {
        setIsEditingDeadlines(true);
        deadlineForm.clearErrors();
        deadlineForm.setData({
            questions_deadline_at: dateInputValue(notice.questions_deadline_at),
            questions_rfi_deadline_at: dateInputValue(notice.questions_rfi_deadline_at),
            rfi_submission_deadline_at: dateInputValue(notice.rfi_submission_deadline_at),
            questions_rfp_deadline_at: dateInputValue(notice.questions_rfp_deadline_at),
            rfp_submission_deadline_at: dateInputValue(notice.rfp_submission_deadline_at),
            award_date_at: dateInputValue(notice.award_date_at),
        });
    };

    const cancelDeadlineEditor = () => {
        setIsEditingDeadlines(false);
        deadlineForm.reset();
        deadlineForm.clearErrors();
    };

    const submitDeadlineEditor = () => {
        deadlineForm.patch(`/app/notices/saved/${notice.id}/deadlines`, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditingDeadlines(false);
                deadlineForm.reset();
                deadlineForm.clearErrors();
            },
        });
    };

    const revokeCaseAccess = (url) => {
        if (!url) {
            return;
        }

        router.delete(url, {
            preserveScroll: true,
        });
    };

    const archiveSavedNotice = () => {
        if (!notice.archived_at) {
            router.patch(`/app/notices/saved/${notice.id}/archive`, {}, {
                preserveScroll: true,
            });
        }
    };

    const createSubmission = () => {
        if (!notice.actions?.can_create_submission || !notice.actions?.create_submission_url) {
            return;
        }

        submissionForm.post(notice.actions.create_submission_url, {
            preserveScroll: true,
        });
    };

    const resetStatusForm = () => {
        statusForm.reset();
        statusForm.clearErrors();
        setOpenClosureStatus(null);
    };

    const submitStatusAction = (action, requiresClosureReason) => {
        if (!notice.actions?.update_status_url) {
            return;
        }

        const payload = {
            status: action.status,
            bid_closure_reason: requiresClosureReason ? (statusForm.data.bid_closure_reason || null) : null,
            bid_closure_note: requiresClosureReason ? (statusForm.data.bid_closure_note || null) : null,
        };

        statusForm.clearErrors();
        router.patch(notice.actions.update_status_url, payload, {
            preserveScroll: true,
            onStart: () => {
                setIsStatusActionProcessing(true);
            },
            onError: (errors) => {
                statusForm.setError(errors);
            },
            onSuccess: () => {
                resetStatusForm();
            },
            onFinish: () => {
                setIsStatusActionProcessing(false);
            },
        });
    };

    const triggerStatusAction = (action) => {
        if (!action) {
            return;
        }

        if (action.requires_closure_reason) {
            openClosureActionForm(action);

            return;
        }

        submitStatusAction(action, false);
    };

    const toggleCommentPhase = (phaseStatus) => {
        setOpenCommentPhases((current) => ({
            ...current,
            [phaseStatus]: !current[phaseStatus],
        }));
    };

    const submitPhaseComment = () => {
        if (!phaseCommentStoreUrl || !canCommentOnCase) {
            return;
        }

        phaseCommentForm.clearErrors();
        phaseCommentForm.post(phaseCommentStoreUrl, {
            preserveScroll: true,
            onSuccess: () => {
                phaseCommentForm.reset('comment');
                phaseCommentForm.clearErrors();
            },
        });
    };

    const openClosureActionForm = (action) => {
        if (openClosureStatus === action.status) {
            resetStatusForm();

            return;
        }

        statusForm.reset();
        statusForm.clearErrors();
        statusForm.setData({
            status: action.status,
            bid_closure_reason: '',
            bid_closure_note: '',
        });
        setOpenClosureStatus(action.status);
    };

    return (
        <CustomerAppLayout title={notice.title} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-3">
                    <Link
                        href={notice.back_url}
                        className="inline-flex items-center text-sm font-medium text-slate-600 transition hover:text-slate-950"
                    >
                        {notice.back_label}
                    </Link>

                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <span
                                    className={classNames(
                                        'inline-flex items-center rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset',
                                        bidStatusBadgeClassName(notice.bid_status),
                                    )}
                                >
                                    {notice.bid_status_label}
                                </span>
                                {notice.archived_at ? (
                                    <span className="inline-flex items-center rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                                        Arkivert
                                    </span>
                                ) : null}
                            </div>

                            <div className="space-y-1.5">
                                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{notice.title}</h1>
                                <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                                    {notice.organization_name || 'Oppdragsgiver ikke registrert'}
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-3">
                            {notice.external_url ? (
                                <a
                                    href={notice.external_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                >
                                    Åpne i Doffin
                                </a>
                            ) : null}
                        </div>
                    </div>
                </section>

                <BidStatusPipeline currentStatus={notice.bid_status} />

                <div className="grid gap-6 xl:grid-cols-[280px_minmax(0,1fr)_360px] xl:items-start">
                    <aside className="space-y-4">
                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="space-y-4">
                                <div>
                                    <h2 className="text-lg font-semibold tracking-tight text-slate-950">Statuspanel</h2>
                                    <p className="mt-1 text-sm text-slate-500">Operativ status, ansvar og nøkkeldatoer.</p>
                                </div>

                                <div className="space-y-3">
                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Nåværende status</div>
                                        <div className="mt-1 text-sm font-semibold text-slate-950">{notice.bid_status_label}</div>
                                        <div className="mt-2 text-sm font-medium text-slate-900">{guidance.phaseTitle}</div>
                                        <p className="mt-1 text-sm leading-6 text-slate-600">{guidance.description}</p>
                                        <div className="mt-3 text-xs leading-5 text-slate-500">{guidance.closureRule}</div>
                                    </div>

                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Ansvarlige</div>
                                        <div className="mt-3 space-y-3">
                                            <div>
                                                <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-500">Bid-manager</div>
                                                <div className="mt-1 text-sm font-semibold text-slate-950">{notice.bid_manager?.name || 'Ikke satt'}</div>
                                                {notice.bid_manager?.bid_role ? (
                                                    <div className="mt-1 text-xs text-slate-500">
                                                        Global bid-rolle: {bidRoleLabel(notice.bid_manager.bid_role)}
                                                    </div>
                                                ) : null}
                                            </div>

                                            <div>
                                                <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-500">Kommersiell eier</div>
                                                <div className="mt-1 text-sm font-semibold text-slate-950">{notice.opportunity_owner?.name || 'Ikke satt'}</div>
                                                {notice.opportunity_owner?.bid_role ? (
                                                    <div className="mt-1 text-xs text-slate-500">
                                                        Global bid-rolle: {bidRoleLabel(notice.opportunity_owner.bid_role)}
                                                    </div>
                                                ) : null}
                                            </div>

                                            <div>
                                                <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-500">Din globale bid-rolle</div>
                                                <div className="mt-1 text-sm font-semibold text-slate-950">{currentUserBidRoleLabel}</div>
                                                <div className="mt-1 text-xs text-slate-500">Gjelder brukerkontoen din, ikke denne saken.</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Sentrale datoer</div>
                                        <div className="mt-3 space-y-3">
                                            <div>
                                                <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-500">Sendt dato</div>
                                                <div className="mt-1 text-sm font-semibold text-slate-950">{formatDate(notice.bid_submitted_at, locale, { hour: '2-digit', minute: '2-digit' })}</div>
                                            </div>
                                            <div>
                                                <div className="text-xs font-medium uppercase tracking-[0.12em] text-slate-500">Lukket dato</div>
                                                <div className="mt-1 text-sm font-semibold text-slate-950">{formatDate(notice.bid_closed_at, locale, { hour: '2-digit', minute: '2-digit' })}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </aside>

                    <main className="space-y-6">
                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="space-y-5">
                                <div>
                                    <h2 className="text-xl font-semibold tracking-tight text-slate-950">Informasjon</h2>
                                    <p className="mt-1 text-sm text-slate-500">Sakens innhold, oppsummering og fasebundet kontekst.</p>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-1">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Kunngjøring</div>
                                        <div className="text-sm font-medium text-slate-900">{notice.notice_id || '—'}</div>
                                    </div>
                                    <div className="space-y-1">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Oppdragsgiver</div>
                                        <div className="text-sm font-medium text-slate-900">{notice.organization_name || '—'}</div>
                                    </div>
                                    <div className="space-y-1">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Publisert</div>
                                        <div className="text-sm font-medium text-slate-900">{formatDate(notice.publication_date, locale)}</div>
                                    </div>
                                    <div className="space-y-1">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Frist</div>
                                        <div className="text-sm font-medium text-slate-900">{formatDate(notice.deadline, locale)}</div>
                                    </div>
                                    <div className="space-y-1">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Neste leveringsfrist</div>
                                        <div className="text-sm font-medium text-slate-900">{deadlineStateLabel(notice, locale)}</div>
                                    </div>
                                    <div className="space-y-1">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">CPV</div>
                                        <div className="text-sm font-medium text-slate-900">{notice.cpv_code || '—'}</div>
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Oppsummering</div>
                                    <div className="mt-2 text-sm leading-7 text-slate-700 whitespace-pre-line">
                                        {notice.summary || 'Ingen oppsummering er registrert ennå.'}
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="space-y-5">
                                <div>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="min-w-0">
                                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">Fasekommentarer</h2>
                                            <p className="mt-1 text-sm text-slate-500">Aktiv fase: {activeCommentPhaseLabel}</p>
                                        </div>

                                        <div className="flex flex-wrap justify-end gap-2">
                                            {commentPhaseOptions.map((phaseOption) => {
                                                const isOpen = Boolean(openCommentPhases[phaseOption.status]);
                                                const isCurrentPhase = notice.bid_status === phaseOption.status;

                                                return (
                                                    <button
                                                        key={phaseOption.status}
                                                        type="button"
                                                        onClick={isCurrentPhase ? undefined : () => toggleCommentPhase(phaseOption.status)}
                                                        aria-expanded={isCurrentPhase ? true : isOpen}
                                                        disabled={isCurrentPhase}
                                                        className={classNames(
                                                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1.5 text-[11px] font-semibold transition disabled:cursor-default',
                                                            isCurrentPhase
                                                                ? 'border-violet-200 bg-violet-50 text-violet-700'
                                                                : isOpen
                                                                    ? 'border-violet-200 bg-violet-50 text-violet-700'
                                                                    : 'border-slate-200 bg-white text-slate-600 hover:border-violet-200 hover:text-violet-700',
                                                        )}
                                                    >
                                                        <span className="text-[10px] font-bold uppercase tracking-[0.14em]">
                                                            {phaseOption.number}
                                                        </span>
                                                        <span className="whitespace-nowrap">
                                                            {phaseOption.label}
                                                        </span>
                                                        {isCurrentPhase ? (
                                                            <span className="rounded-full bg-white/70 px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.12em] text-violet-700">
                                                                Aktiv
                                                            </span>
                                                        ) : (
                                                            <span className="text-[10px] leading-none">
                                                                {isOpen ? '−' : '+'}
                                                            </span>
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        Aktiv fase
                                    </div>
                                    <div className="mt-2 text-sm font-semibold text-slate-950">
                                        {guidance.phaseTitle}
                                    </div>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">
                                        {guidance.description}
                                    </p>
                                    <div className="mt-3 text-xs leading-5 text-slate-500">{guidance.closureRule}</div>

                                    <div className="mt-4 space-y-3">
                                        {activePhaseCommentEntries.length > 0 ? (
                                            activePhaseCommentEntries.map((comment) => (
                                                <PhaseCommentCard key={comment.id} comment={comment} locale={locale} />
                                            ))
                                        ) : (
                                            <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-4 text-sm text-slate-500">
                                                Ingen kommentarer er registrert for aktiv fase ennå.
                                            </div>
                                        )}
                                    </div>

                                    {canCommentOnCase ? (
                                        <form
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                submitPhaseComment();
                                            }}
                                            className="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-white px-4 py-4"
                                        >
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                Ny kommentar
                                            </div>

                                            <label className="space-y-1.5">
                                                <span className="text-sm font-medium text-slate-800">
                                                    Kommentar for aktiv fase
                                                </span>
                                                <textarea
                                                    value={phaseCommentForm.data.comment}
                                                    onChange={(event) => phaseCommentForm.setData('comment', event.target.value)}
                                                    rows={4}
                                                    className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                    placeholder="Skriv en kommentar for denne fasen"
                                                />
                                            </label>

                                            {(phaseCommentForm.errors.comment || errors.comment) ? (
                                                <p className="text-sm text-rose-600">{phaseCommentForm.errors.comment ?? errors.comment}</p>
                                            ) : null}

                                            <div className="flex flex-wrap gap-3">
                                                <button
                                                    type="submit"
                                                    disabled={phaseCommentForm.processing || phaseCommentForm.data.comment.trim() === ''}
                                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {phaseCommentForm.processing ? 'Lagrer...' : 'Lagre kommentar'}
                                                </button>
                                            </div>
                                        </form>
                                    ) : null}
                                </div>

                                <div className="space-y-3">
                                    {commentPhaseOptions.map((phaseOption) => {
                                        const isOpen = Boolean(openCommentPhases[phaseOption.status]);
                                        const comments = phaseCommentGroups[phaseOption.status] ?? [];
                                        const isCurrentPhase = notice.bid_status === phaseOption.status;

                                        if (isCurrentPhase || !isOpen) {
                                            return null;
                                        }

                                        return (
                                            <section
                                                key={phaseOption.status}
                                                className="rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-[0_4px_14px_rgba(15,23,42,0.04)]"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                            Fase {phaseOption.number}
                                                        </div>
                                                        <h3 className="mt-1 text-sm font-semibold text-slate-950">
                                                            {phaseOption.label}
                                                        </h3>
                                                    </div>

                                                    <button
                                                        type="button"
                                                        onClick={() => toggleCommentPhase(phaseOption.status)}
                                                        className="inline-flex min-h-8 items-center justify-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                    >
                                                        Skjul
                                                    </button>
                                                </div>

                                                {comments.length > 0 ? (
                                                    <div className="mt-4 space-y-3">
                                                        {comments.map((comment) => (
                                                            <PhaseCommentCard key={comment.id} comment={comment} locale={locale} />
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <div className="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                                        Ingen kommentarer er registrert for denne fasen ennå.
                                                    </div>
                                                )}
                                            </section>
                                        );
                                    })}
                                </div>
                            </div>
                        </section>

                        {shouldShowSubmissions ? (
                            <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h2 className="text-xl font-semibold tracking-tight text-slate-950">Forhandlingsrunder</h2>
                                        <p className="mt-1 text-sm text-slate-500">Registrerte innsendinger for denne saken.</p>
                                    </div>
                                </div>

                                {notice.submissions.length === 0 ? (
                                    <div className="mt-5 rounded-2xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                                        Ingen innsendinger er registrert ennå.
                                    </div>
                                ) : (
                                    <div className="mt-5 space-y-3">
                                        {notice.submissions.map((submission) => (
                                            <article
                                                key={submission.id}
                                                className="rounded-2xl border border-slate-200 px-4 py-4"
                                            >
                                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <div className="text-sm font-semibold text-slate-950">{submission.label}</div>
                                                        <div className="mt-1 text-xs font-medium uppercase tracking-[0.12em] text-slate-500">
                                                            Runde {submission.sequence_number}
                                                        </div>
                                                    </div>

                                                    <div
                                                        className={classNames(
                                                            'text-sm font-medium',
                                                            submission.submitted_at ? 'text-slate-900' : 'text-slate-500',
                                                        )}
                                                    >
                                                        {submission.submitted_at ? formatDate(submission.submitted_at, locale, { hour: '2-digit', minute: '2-digit' }) : 'Ikke registrert'}
                                                    </div>
                                                </div>
                                            </article>
                                        ))}
                                    </div>
                                )}
                            </section>
                        ) : null}
                    </main>

                    <aside className="space-y-3">
                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="space-y-4">
                                <div>
                                    <h2 className="text-lg font-semibold tracking-tight text-slate-950">Aksjoner</h2>
                                    <p className="mt-1 text-sm text-slate-500">Alt du kan gjøre på saken akkurat nå.</p>
                                </div>

                                <div className="space-y-3">
                                    <ActionAccordionSection
                                        title="Neste beslutning"
                                        summary={nextDecisionSummary}
                                        hint={guidance.phaseTitle}
                                        isOpen={openActionSection === 'decision'}
                                        onToggle={() => setOpenActionSection((current) => (current === 'decision' ? null : 'decision'))}
                                    >
                                        <p className="text-sm leading-6 text-slate-600">{guidance.nextStepDescription}</p>

                                        {statusForm.errors.status ? (
                                            <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-3 text-sm font-medium text-rose-700">
                                                {statusForm.errors.status}
                                            </div>
                                        ) : null}

                                        <div className="mt-4 space-y-2">
                                            {primaryAction ? (
                                                <button
                                                    type="button"
                                                    onClick={() => triggerStatusAction(primaryAction)}
                                                    disabled={isStatusActionProcessing || !notice.actions?.update_status_url}
                                                    className={classNames(
                                                        'inline-flex min-h-11 w-full items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                                                        actionButtonClassName(primaryAction.tone, primaryAction.status),
                                                    )}
                                                >
                                                    {statusActionLabel(primaryAction.status)}
                                                </button>
                                            ) : null}

                                            {secondaryActions.length > 0 ? (
                                                <div className="space-y-2">
                                                    {secondaryActions.map((action) => (
                                                        <button
                                                            key={action.status}
                                                            type="button"
                                                            onClick={() => triggerStatusAction(action)}
                                                            disabled={isStatusActionProcessing || !notice.actions?.update_status_url}
                                                            className={classNames(
                                                                'inline-flex min-h-11 w-full items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                                                                actionButtonClassName(action.tone, action.status),
                                                            )}
                                                        >
                                                            {statusActionLabel(action.status)}
                                                        </button>
                                                    ))}
                                                </div>
                                            ) : null}

                                            {!primaryAction && secondaryActions.length === 0 ? (
                                                <div className="rounded-xl border border-dashed border-slate-200 px-3 py-3 text-sm text-slate-500">
                                                    {noStatusActionsMessage}
                                                </div>
                                            ) : null}
                                        </div>

                                        {activeClosureAction ? (
                                            <form
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    submitStatusAction(activeClosureAction, true);
                                                }}
                                                className="mt-4 space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"
                                            >
                                                {closureActionGuidance(activeClosureAction.status) ? (
                                                    <div className={classNames('rounded-xl border px-3 py-3 text-sm font-medium', closureActionGuidance(activeClosureAction.status)?.className)}>
                                                        {closureActionGuidance(activeClosureAction.status)?.text}
                                                    </div>
                                                ) : null}

                                                <div className="space-y-1.5">
                                                    <label className="text-sm font-medium text-slate-800" htmlFor="bid_closure_reason">
                                                        Avslutningsårsak
                                                    </label>
                                                    <select
                                                        id="bid_closure_reason"
                                                        value={statusForm.data.bid_closure_reason}
                                                        onChange={(event) => statusForm.setData('bid_closure_reason', event.target.value)}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                    >
                                                        <option value="">Velg avslutningsårsak</option>
                                                        {closureReasonOptions.map((option) => (
                                                            <option key={option.value} value={option.value}>
                                                                {option.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {statusForm.errors.bid_closure_reason ? (
                                                        <p className="text-sm text-rose-600">{statusForm.errors.bid_closure_reason}</p>
                                                    ) : null}
                                                </div>

                                                <div className="space-y-1.5">
                                                    <label className="text-sm font-medium text-slate-800" htmlFor="bid_closure_note">
                                                        Notat
                                                    </label>
                                                    <textarea
                                                        id="bid_closure_note"
                                                        value={statusForm.data.bid_closure_note}
                                                        onChange={(event) => statusForm.setData('bid_closure_note', event.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                        placeholder="Valgfritt notat"
                                                    />
                                                </div>

                                                <div className="flex flex-wrap gap-3">
                                                    <button
                                                        type="submit"
                                                        disabled={isStatusActionProcessing}
                                                        className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {isStatusActionProcessing ? 'Oppdaterer...' : statusActionLabel(activeClosureAction.status)}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={resetStatusForm}
                                                        disabled={isStatusActionProcessing}
                                                        className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        Avbryt
                                                    </button>
                                                </div>
                                            </form>
                                        ) : null}

                                        {notice.actions?.can_create_submission ? (
                                            <button
                                                type="button"
                                                onClick={createSubmission}
                                                disabled={submissionForm.processing}
                                                className="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {submissionForm.processing ? 'Registrerer...' : 'Legg til ny innsending'}
                                            </button>
                                        ) : null}
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title="Deadlines"
                                        summary={deadlineSummary}
                                        hint="Oppdater ved endring"
                                        isOpen={openActionSection === 'deadlines'}
                                        onToggle={() => setOpenActionSection((current) => (current === 'deadlines' ? null : 'deadlines'))}
                                    >
                                        {!isEditingDeadlines ? (
                                            <button
                                                type="button"
                                                onClick={openDeadlineEditor}
                                                className="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                            >
                                                Oppdater deadlines
                                            </button>
                                        ) : null}

                                        {isEditingDeadlines ? (
                                            <form
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    submitDeadlineEditor();
                                                }}
                                                className="space-y-3"
                                            >
                                                {[
                                                    { key: 'questions_deadline_at', label: 'Frist spørsmål' },
                                                    { key: 'questions_rfi_deadline_at', label: 'Frist spørsmål RFI' },
                                                    { key: 'rfi_submission_deadline_at', label: 'Innlevering RFI' },
                                                    { key: 'questions_rfp_deadline_at', label: 'Frist spørsmål RFP' },
                                                    { key: 'rfp_submission_deadline_at', label: 'Innlevering RFP' },
                                                    { key: 'award_date_at', label: 'Tildelingsdato' },
                                                ].map((field) => (
                                                    <label key={field.key} className="space-y-2">
                                                        <span className="text-sm font-medium text-slate-700">{field.label}</span>
                                                        <input
                                                            type="date"
                                                            value={deadlineForm.data[field.key]}
                                                            onChange={(event) => deadlineForm.setData(field.key, event.target.value)}
                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        />
                                                        {deadlineForm.errors[field.key] ? (
                                                            <p className="text-sm text-rose-600">{deadlineForm.errors[field.key]}</p>
                                                        ) : null}
                                                    </label>
                                                ))}

                                                <div className="flex flex-wrap gap-2.5 pt-1">
                                                    <button
                                                        type="submit"
                                                        disabled={!isDeadlineDirty || deadlineForm.processing}
                                                        className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {deadlineForm.processing ? 'Lagrer...' : 'Lagre'}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={cancelDeadlineEditor}
                                                        disabled={deadlineForm.processing}
                                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        Avbryt
                                                    </button>
                                                </div>
                                            </form>
                                        ) : null}
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title="Sakens bid-manager"
                                        summary={bidManagerSummary}
                                        hint="Operativt ansvar"
                                        isOpen={openActionSection === 'bid-manager'}
                                        onToggle={() => setOpenActionSection((current) => (current === 'bid-manager' ? null : 'bid-manager'))}
                                    >
                                        {notice.actions?.update_bid_manager_url ? (
                                            <form
                                                method="post"
                                                action={notice.actions.update_bid_manager_url}
                                                className="space-y-3"
                                            >
                                                {csrfToken ? <input type="hidden" name="_token" value={csrfToken} /> : null}
                                                <input type="hidden" name="_method" value="patch" />
                                                <div className="space-y-1.5">
                                                    <label className="text-sm font-medium text-slate-800" htmlFor="bid_manager_user_id">
                                                        Sakens bid-manager
                                                    </label>
                                                    <select
                                                        id="bid_manager_user_id"
                                                        name="bid_manager_user_id"
                                                        value={bidManagerForm.data.bid_manager_user_id}
                                                        onChange={(event) => bidManagerForm.setData('bid_manager_user_id', event.target.value)}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                    >
                                                        <option value="">Ikke satt</option>
                                                        {bidManagerOptions.map((option) => (
                                                            <option key={option.value} value={option.value}>
                                                                {option.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <p className="text-xs text-slate-400">
                                                        Velg operativt ansvarlig for tilbudsprosessen. Kun brukere med global bid-rolle Bid-manager kan velges.
                                                    </p>
                                                    {(bidManagerForm.errors.bid_manager_user_id || errors.bid_manager_user_id) ? (
                                                        <p className="text-sm text-rose-600">{bidManagerForm.errors.bid_manager_user_id ?? errors.bid_manager_user_id}</p>
                                                    ) : null}
                                                </div>

                                                <button
                                                    type="submit"
                                                    disabled={!isBidManagerDirty}
                                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    Lagre bid-manager
                                                </button>
                                            </form>
                                        ) : null}
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title="Sakens kommersielle eier"
                                        summary={opportunityOwnerSummary}
                                        hint="Kommersielt ansvar"
                                        isOpen={openActionSection === 'opportunity-owner'}
                                        onToggle={() => setOpenActionSection((current) => (current === 'opportunity-owner' ? null : 'opportunity-owner'))}
                                    >
                                        {notice.actions?.update_opportunity_owner_url ? (
                                            <form
                                                method="post"
                                                action={notice.actions.update_opportunity_owner_url}
                                                className="space-y-3"
                                            >
                                                {csrfToken ? <input type="hidden" name="_token" value={csrfToken} /> : null}
                                                <input type="hidden" name="_method" value="patch" />
                                                <div className="space-y-1.5">
                                                    <label className="text-sm font-medium text-slate-800" htmlFor="opportunity_owner_user_id">
                                                        Sakens kommersielle eier
                                                    </label>
                                                    <select
                                                        id="opportunity_owner_user_id"
                                                        name="opportunity_owner_user_id"
                                                        value={opportunityOwnerForm.data.opportunity_owner_user_id}
                                                        onChange={(event) => opportunityOwnerForm.setData('opportunity_owner_user_id', event.target.value)}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                    >
                                                        <option value="">Ikke satt</option>
                                                        {opportunityOwnerOptions.map((option) => (
                                                            <option key={option.value} value={option.value}>
                                                                {option.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <p className="text-xs text-slate-400">
                                                        Velg selger eller kommersielt ansvarlig for saken. Global bid-rolle vises kun som brukerinfo.
                                                    </p>
                                                    {(opportunityOwnerForm.errors.opportunity_owner_user_id || errors.opportunity_owner_user_id) ? (
                                                        <p className="text-sm text-rose-600">{opportunityOwnerForm.errors.opportunity_owner_user_id ?? errors.opportunity_owner_user_id}</p>
                                                    ) : null}
                                                </div>

                                                <button
                                                    type="submit"
                                                    disabled={!isOpportunityOwnerDirty}
                                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    Lagre kommersiell eier
                                                </button>
                                            </form>
                                        ) : null}
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title="Eksplisitt saksadgang"
                                        summary={caseAccessSummary}
                                        hint="Saksspesifikk tilgang"
                                        isOpen={openActionSection === 'case-access'}
                                        onToggle={() => setOpenActionSection((current) => (current === 'case-access' ? null : 'case-access'))}
                                    >
                                        <div className="space-y-3">
                                            {caseAccessEntries.length > 0 ? (
                                                <div className="space-y-3">
                                                    {caseAccessEntries.map((access) => (
                                                        <div
                                                            key={access.id}
                                                            className="rounded-2xl border border-slate-200 bg-white px-4 py-4"
                                                        >
                                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                                <div className="space-y-2">
                                                                    <div>
                                                                        <div className="text-sm font-semibold text-slate-950">
                                                                            {access.user?.name || 'Ukjent bruker'}
                                                                        </div>
                                                                        <div className="text-xs text-slate-500">
                                                                            {access.user?.email || '—'}
                                                                        </div>
                                                                    </div>

                                                                    <div className="flex flex-wrap gap-2 text-xs text-slate-500">
                                                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                                                                            {accessRoleLabel(access.access_role)}
                                                                        </span>
                                                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                                                                            Gitt av {access.granted_by?.name || '—'}
                                                                        </span>
                                                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                                                                            {access.granted_at ? formatDate(access.granted_at, locale, { hour: '2-digit', minute: '2-digit' }) : '—'}
                                                                        </span>
                                                                    </div>
                                                                </div>

                                                                {canManageCaseAccess ? (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => revokeCaseAccess(access.revoke_url)}
                                                                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                                                    >
                                                                        Tilbakekall
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : (
                                                <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">
                                                    Ingen eksplisitt saksadgang er gitt ennå.
                                                </div>
                                            )}

                                            {canManageCaseAccess ? (
                                                <form onSubmit={grantCaseAccess} className="space-y-4 border-t border-slate-200 pt-4">
                                                    <div className="grid gap-4">
                                                        <div className="space-y-1.5">
                                                            <label className="text-sm font-medium text-slate-800" htmlFor="case_access_user_id">
                                                                Bruker
                                                            </label>
                                                            <select
                                                                id="case_access_user_id"
                                                                name="user_id"
                                                                value={caseAccessForm.data.user_id}
                                                                onChange={(event) => caseAccessForm.setData('user_id', event.target.value)}
                                                                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                            >
                                                                <option value="">Velg bid-bidragsyter eller lesetilgang</option>
                                                                {caseAccessUserOptions.map((option) => (
                                                                    <option key={option.value} value={option.value}>
                                                                        {option.label}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            {(caseAccessForm.errors.user_id || errors.user_id) ? (
                                                                <p className="text-sm text-rose-600">{caseAccessForm.errors.user_id ?? errors.user_id}</p>
                                                            ) : null}
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <label className="text-sm font-medium text-slate-800" htmlFor="case_access_role">
                                                                Tilgangsnivå
                                                            </label>
                                                            <select
                                                                id="case_access_role"
                                                                name="access_role"
                                                                value={caseAccessForm.data.access_role}
                                                                onChange={(event) => caseAccessForm.setData('access_role', event.target.value)}
                                                                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                            >
                                                                {caseAccessRoleOptions.map((option) => (
                                                                    <option key={option.value} value={option.value}>
                                                                        {accessRoleLabel(option.value)}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            {(caseAccessForm.errors.access_role || errors.access_role) ? (
                                                                <p className="text-sm text-rose-600">{caseAccessForm.errors.access_role ?? errors.access_role}</p>
                                                            ) : null}
                                                        </div>
                                                    </div>

                                                    <div className="flex flex-wrap gap-3">
                                                        <button
                                                            type="submit"
                                                            disabled={!isCaseAccessDirty || caseAccessForm.processing || !caseAccess.store_url}
                                                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            {caseAccessForm.processing ? 'Lagrer...' : 'Gi tilgang'}
                                                        </button>
                                                    </div>
                                                </form>
                                            ) : (
                                                <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">
                                                    Du har ikke tilgang til å administrere eksplisitt saksadgang for denne saken.
                                                </div>
                                            )}
                                        </div>
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title="Administrasjon"
                                        summary={administrationSummary}
                                        hint="Sekundære handlinger"
                                        isOpen={openActionSection === 'administration'}
                                        onToggle={() => setOpenActionSection((current) => (current === 'administration' ? null : 'administration'))}
                                    >
                                        <div className="flex flex-wrap gap-3">
                                            {!notice.archived_at ? (
                                                <button
                                                    type="button"
                                                    onClick={archiveSavedNotice}
                                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                >
                                                    Arkiver sak
                                                </button>
                                            ) : null}
                                        </div>
                                    </ActionAccordionSection>
                                </div>
                            </div>
                        </section>
                    </aside>
                </div>
            </div>
        </CustomerAppLayout>
    );
}
