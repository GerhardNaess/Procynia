import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import BidStatusPipeline from '../../../Components/App/BidStatusPipeline';
import GoNoGoAssessment from './GoNoGoAssessment';
import InfoHint from '../../../Components/App/InfoHint';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';

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

function formatFileSize(bytes) {
    if (!bytes) {
        return '—';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
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

function infoItemStatusBadgeTone(status) {
    switch (status) {
        case 'open':    return 'emerald';
        case 'waiting': return 'amber';
        default:        return 'slate';
    }
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

function bidRoleLabel(role, text) {
    switch (role) {
        case 'bid_manager':
            return text.bidRoleManager;
        case 'viewer':
            return text.bidRoleViewer;
        case 'contributor':
        default:
            return text.bidRoleContributor;
    }
}

function accessRoleLabel(role, text) {
    switch (role) {
        case 'viewer':
            return text.bidRoleViewer;
        case 'contributor':
        default:
            return text.bidRoleContributor;
    }
}

function noticeSourceTypeLabel(notice, text) {
    if (notice.source_type_label) {
        return notice.source_type_label;
    }

    return notice.source_type === 'private_request'
        ? text.sourcePrivateRequest
        : text.sourcePublicNotice;
}

function noticeExternalLinkLabel(notice, text) {
    return notice.source_type === 'private_request'
        ? text.openLink
        : text.openInDoffin;
}

function noticeSourceBadgeClassName(notice) {
    return notice.source_type === 'private_request'
        ? 'bg-violet-100 text-violet-700 ring-violet-200'
        : 'bg-slate-100 text-slate-700 ring-slate-200';
}

function statusActionLabel(status, text) {
    switch (status) {
        case 'qualifying':
            return text.goToQualifying;
        case 'go_no_go':
            return text.goToGoNoGo;
        case 'in_progress':
            return text.goToInProgress;
        case 'submitted':
            return text.goToSubmitted;
        case 'negotiation':
            return text.goToNegotiation;
        case 'won':
            return text.markAsWon;
        case 'lost':
            return text.markAsLost;
        case 'no_go':
            return text.setNoGo;
        case 'withdrawn':
            return text.withdrawCase;
        case 'archived':
            return text.archiveCaseAction;
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

function lifecycleGuidance(status, isArchived, text) {
    if (isArchived) {
        return {
            phaseTitle: text.phaseArchivedTitle,
            description: text.phaseArchivedDescription,
            closureRule: text.phaseArchivedClosureRule,
            nextStepDescription: text.phaseArchivedNextStep,
        };
    }

    switch (status) {
        case 'discovered':
            return {
                phaseTitle: text.phaseDiscoveredTitle,
                description: text.phaseDiscoveredDescription,
                closureRule: text.phaseDiscoveredClosureRule,
                nextStepDescription: text.phaseDiscoveredNextStep,
            };
        case 'qualifying':
            return {
                phaseTitle: text.phaseQualifyingTitle,
                description: text.phaseQualifyingDescription,
                closureRule: text.phaseQualifyingClosureRule,
                nextStepDescription: text.phaseQualifyingNextStep,
            };
        case 'go_no_go':
            return {
                phaseTitle: text.phaseGoNoGoTitle,
                description: text.phaseGoNoGoDescription,
                closureRule: text.phaseGoNoGoClosureRule,
                nextStepDescription: text.phaseGoNoGoNextStep,
            };
        case 'in_progress':
            return {
                phaseTitle: text.phaseInProgressTitle,
                description: text.phaseInProgressDescription,
                closureRule: text.phaseInProgressClosureRule,
                nextStepDescription: text.phaseInProgressNextStep,
            };
        case 'submitted':
            return {
                phaseTitle: text.phaseSubmittedTitle,
                description: text.phaseSubmittedDescription,
                closureRule: text.phaseSubmittedClosureRule,
                nextStepDescription: text.phaseSubmittedNextStep,
            };
        case 'negotiation':
            return {
                phaseTitle: text.phaseNegotiationTitle,
                description: text.phaseNegotiationDescription,
                closureRule: text.phaseNegotiationClosureRule,
                nextStepDescription: text.phaseNegotiationNextStep,
            };
        case 'no_go':
            return {
                phaseTitle: text.phaseNoGoTitle,
                description: text.phaseNoGoDescription,
                closureRule: text.phaseNoGoClosureRule,
                nextStepDescription: text.phaseNoGoNextStep,
            };
        case 'withdrawn':
            return {
                phaseTitle: text.phaseWithdrawnTitle,
                description: text.phaseWithdrawnDescription,
                closureRule: text.phaseWithdrawnClosureRule,
                nextStepDescription: text.phaseWithdrawnNextStep,
            };
        case 'won':
            return {
                phaseTitle: text.phaseWonTitle,
                description: text.phaseWonDescription,
                closureRule: text.phaseWonClosureRule,
                nextStepDescription: text.phaseWonNextStep,
            };
        case 'lost':
            return {
                phaseTitle: text.phaseLostTitle,
                description: text.phaseLostDescription,
                closureRule: text.phaseLostClosureRule,
                nextStepDescription: text.phaseLostNextStep,
            };
        default:
            return {
                phaseTitle: text.phaseDefaultTitle,
                description: text.phaseDefaultDescription,
                closureRule: text.phaseDefaultClosureRule,
                nextStepDescription: text.phaseDefaultNextStep,
            };
    }
}

function closureActionGuidance(actionStatus, text) {
    switch (actionStatus) {
        case 'no_go':
            return {
                className: 'border-amber-200 bg-amber-50 text-amber-800',
                text: text.closureNoGo,
            };
        case 'withdrawn':
            return {
                className: 'border-rose-200 bg-rose-50 text-rose-800',
                text: text.closureWithdrawn,
            };
        default:
            return null;
    }
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
                        <div className="text-sm leading-6 text-slate-500">
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

function PhaseCommentCard({ comment, locale, text }) {
    return (
        <article className="rounded-2xl border border-slate-200 bg-white px-4 py-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="text-sm font-semibold text-slate-950">
                            {comment.user?.name || text.unknownUser}
                        </div>
                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700">
                            {comment.user?.bid_role_label || text.bidRoleContributor}
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
    const { auth, errors = {}, translations = {}, goNoGoData = null } = page.props;
    const tsn = translations?.saved_notice ?? {};
    const common = translations?.common ?? {};
    const frontend = translations?.frontend ?? {};
    const locale = document.documentElement.lang || 'no-NO';
    const infoItems = notice.info_items;
    const infoItemDefaults = infoItems.defaults;
    const documents = notice.documents ?? [];
    const downloadAllUrl = notice.download_all_url ?? null;
    const submissionForm = useForm({});
    const statusForm = useForm({
        status: '',
        bid_closure_reason: '',
        bid_closure_note: '',
    });
    const archiveHistoryForm = useForm({
        history_type: '',
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
        award_date_at: '',
        reference_number: '',
        contact_person_name: '',
        contact_person_email: '',
        notes: '',
        business_reviews: [],
    });
    const phaseCommentForm = useForm({
        comment: '',
    });
    const infoItemForm = useForm({
        type: infoItemDefaults.type,
        direction: infoItemDefaults.direction,
        channel: infoItemDefaults.channel,
        subject: '',
        body: '',
        status: infoItemDefaults.status,
        requires_response: false,
        response_due_at: '',
        owner_user_id: '',
    });
    const closeInfoItemForm = useForm({
        closure_comment: '',
    });
    const caseAccessForm = useForm({
        user_id: '',
        access_role: notice.actions?.case_access?.access_role_options?.[0]?.value ?? 'contributor',
    });
    const [isEditingDeadlines, setIsEditingDeadlines] = useState(false);
    const [isCreatingInfoItem, setIsCreatingInfoItem] = useState(false);
    const [closingInfoItemId, setClosingInfoItemId] = useState(null);
    const [openClosureStatus, setOpenClosureStatus] = useState(null);
    const [openActionSection, setOpenActionSection] = useState('decision');
    const [openCommentPhases, setOpenCommentPhases] = useState({});
    const [isActivePhaseExpanded, setIsActivePhaseExpanded] = useState(true);
    const [isStatusActionProcessing, setIsStatusActionProcessing] = useState(false);
    const [isArchiveHistoryFormOpen, setIsArchiveHistoryFormOpen] = useState(false);
    const shouldShowSubmissions = notice.submissions.length > 0
        || notice.bid_status === 'submitted'
        || notice.bid_status === 'negotiation';
    const statusActions = filterVisibleStatusActions(notice.bid_status, notice.actions?.status_actions ?? []);
    const closureReasonOptions = notice.actions?.closure_reasons ?? [];
    const opportunityOwnerOptions = notice.actions?.opportunity_owner_options ?? [];
    const bidManagerOptions = notice.actions?.bid_manager_options ?? [];
    const historyTypeOptions = notice.actions?.history_type_options ?? [];
    const archiveUrl = notice.actions?.archive_url ?? null;
    const canArchiveNotice = notice.actions?.can_archive ?? Boolean(archiveUrl);
    const caseAccess = notice.actions?.case_access ?? {};
    const caseAccessUserOptions = caseAccess.user_options ?? [];
    const caseAccessRoleOptions = caseAccess.access_role_options ?? [];
    const caseAccessEntries = caseAccess.accesses ?? [];
    const caseAccessSummary = caseAccessEntries.length > 0
        ? tsn.case_access_active_users.replace(':count', String(caseAccessEntries.length))
        : tsn.case_access_none_active;
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
        ? tsn.no_more_actions_archived
        : tsn.no_more_actions;
    const currentUserBidRoleLabel = bidRoleLabel(auth?.user?.bid_role, tsn);
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
    const guidance = lifecycleGuidance(notice.bid_status, Boolean(notice.archived_at), tsn);
    const commentPhaseOptions = [
        { status: 'qualifying', number: '2', label: tsn.phaseOptionQualifying },
        { status: 'go_no_go', number: '3', label: tsn.phaseOptionGoNoGo },
        { status: 'in_progress', number: '4', label: tsn.phaseOptionInProgress },
        { status: 'negotiation', number: '6', label: tsn.phaseOptionNegotiation },
    ].map((option) => ({
        ...option,
        guidance: lifecycleGuidance(option.status, Boolean(notice.archived_at), tsn),
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
    const isPrivateRequest = notice.source_type === 'private_request';
    const sourceTypeLabel = noticeSourceTypeLabel(notice, tsn);
    const externalLinkLabel = noticeExternalLinkLabel(notice, tsn);
    const sourceBadgeClassName = noticeSourceBadgeClassName(notice);
    const businessReviews = notice.business_reviews ?? [];
    const infoItemEntries = infoItems.items;
    const infoItemSummary = infoItemEntries.length > 0
        ? tsn.registered_actions_summary.replace(':count', String(infoItemEntries.length))
        : tsn.no_registered_actions;
    const infoItemTypeOptions = infoItems.type_options;
    const infoItemDirectionOptions = infoItems.direction_options;
    const infoItemChannelOptions = infoItems.channel_options;
    const infoItemStatusOptions = infoItems.status_options;
    const infoItemOwnerOptions = infoItems.owner_options;
    const bidManagerSummary = notice.bid_manager?.name || tsn.not_set;
    const opportunityOwnerSummary = notice.opportunity_owner?.name || tsn.not_set;
    const administrationSummary = notice.archived_at ? tsn.archived_case_summary : tsn.secondary_actions;
    const nextDecisionSummary = primaryAction ? statusActionLabel(primaryAction.status, tsn) : noStatusActionsMessage;
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
            award_date_at: dateInputValue(notice.award_date_at),
            reference_number: notice.reference_number ?? '',
            contact_person_name: notice.contact_person_name ?? '',
            contact_person_email: notice.contact_person_email ?? '',
            notes: notice.notes ?? '',
            business_reviews: (notice.business_reviews ?? []).map((review) => ({
                id: review.id,
                business_review_at: dateInputValue(review.business_review_at),
            })),
        });
    };

    const cancelDeadlineEditor = () => {
        setIsEditingDeadlines(false);
        deadlineForm.reset();
        deadlineForm.clearErrors();
    };

    const addBusinessReview = () => {
        deadlineForm.setData('business_reviews', [
            ...deadlineForm.data.business_reviews,
            { id: null, business_review_at: '' },
        ]);
    };

    const updateBusinessReviewAt = (index, value) => {
        deadlineForm.setData(
            'business_reviews',
            deadlineForm.data.business_reviews.map((review, currentIndex) => (
                currentIndex === index
                    ? { ...review, business_review_at: value }
                    : review
            )),
        );
    };

    const removeBusinessReview = (index) => {
        deadlineForm.setData(
            'business_reviews',
            deadlineForm.data.business_reviews.filter((_, currentIndex) => currentIndex !== index),
        );
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

    const openArchiveSavedNoticeForm = () => {
        if (notice.archived_at || !canArchiveNotice) {
            return;
        }

        setIsArchiveHistoryFormOpen(true);
        archiveHistoryForm.clearErrors();
        archiveHistoryForm.setData('history_type', '');
    };

    const cancelArchiveSavedNoticeForm = () => {
        setIsArchiveHistoryFormOpen(false);
        archiveHistoryForm.reset();
        archiveHistoryForm.clearErrors();
    };

    const submitArchiveSavedNotice = (event = null) => {
        event?.preventDefault?.();

        if (!archiveUrl || archiveHistoryForm.data.history_type.trim() === '') {
            return;
        }

        archiveHistoryForm.clearErrors();
        archiveHistoryForm.patch(archiveUrl, {
            preserveScroll: true,
            onSuccess: () => {
                cancelArchiveSavedNoticeForm();
            },
        });
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

    const openInfoItemCreator = () => {
        setIsCreatingInfoItem(true);
        infoItemForm.reset();
        infoItemForm.clearErrors();
    };

    const cancelInfoItemCreator = () => {
        setIsCreatingInfoItem(false);
        infoItemForm.reset();
        infoItemForm.clearErrors();
    };

    const submitInfoItemCreator = () => {
        if (!infoItems.store_url) {
            return;
        }

        infoItemForm.clearErrors();
        infoItemForm.post(infoItems.store_url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setIsCreatingInfoItem(false);
                infoItemForm.reset();
                infoItemForm.clearErrors();
            },
        });
    };

    const openInfoItemCloser = (item) => {
        if (!item?.can_close || item.status === 'closed') {
            return;
        }

        if (closingInfoItemId === item.id) {
            cancelInfoItemCloser();

            return;
        }

        closeInfoItemForm.reset();
        closeInfoItemForm.clearErrors();
        setClosingInfoItemId(item.id);
    };

    const cancelInfoItemCloser = () => {
        setClosingInfoItemId(null);
        closeInfoItemForm.reset();
        closeInfoItemForm.clearErrors();
    };

    const submitInfoItemCloser = (item) => {
        if (!item?.close_url) {
            return;
        }

        closeInfoItemForm.clearErrors();
        closeInfoItemForm.patch(item.close_url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                cancelInfoItemCloser();
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
                                <span
                                    className={classNames(
                                        'inline-flex items-center rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset',
                                        sourceBadgeClassName,
                                    )}
                                >
                                    {sourceTypeLabel}
                                </span>
                                {notice.archived_at && notice.history_type_label ? (
                                    <span className="inline-flex items-center rounded-full bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">
                                        {tsn.type_prefix} {notice.history_type_label}
                                    </span>
                                ) : null}
                                {notice.archived_at ? (
                                    <span className="inline-flex items-center rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                                        {tsn.archived}
                                    </span>
                                ) : null}
                            </div>

                            <div className="space-y-1.5">
                                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{notice.title}</h1>
                                <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                                    {notice.organization_name || tsn.organization_unknown}
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-start gap-3">
                            {notice.external_url ? (
                                <a
                                    href={notice.external_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                >
                                    {externalLinkLabel}
                                </a>
                            ) : null}
                            <PageHelpButton
                                buttonLabel={tsn.page_help_button ?? 'Hjelp'}
                                title={tsn.page_help_title ?? 'Om denne saken'}
                                intro={tsn.page_help_intro ?? 'Dette er arbeidsrommet for én lagret kunngjøring.'}
                                sections={[
                                    {
                                        title: tsn.page_help_section_overview ?? 'Hva siden brukes til',
                                        items: [
                                            {
                                                title: tsn.page_help_item_status_title ?? 'Status og ansvar',
                                                text: tsn.page_help_item_status_text ?? 'Sett bid-status, tilordne bid-manager og kommersiell eier, og registrer sentrale datoer.',
                                            },
                                            {
                                                title: tsn.page_help_item_documents_title ?? 'Dokumenter og AI',
                                                text: tsn.page_help_item_documents_text ?? 'Last opp anbudsdokumenter og start AI-analyse for å ekstrahere krav og generere svarutkast.',
                                            },
                                            {
                                                title: tsn.page_help_item_review_title ?? 'Business Review',
                                                text: tsn.page_help_item_review_text ?? 'Opprett Business Review for formell go/no-go-beslutning.',
                                            },
                                        ],
                                    },
                                ]}
                            />
                        </div>
                    </div>
                </section>

                <BidStatusPipeline currentStatus={notice.bid_status} />

                <div className="grid gap-6 xl:grid-cols-[280px_minmax(0,1fr)_360px] xl:items-start">
                    <aside className="space-y-4">
                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="space-y-4">
                                <div>
                                    <h2 className="text-lg font-semibold tracking-tight text-slate-950">{tsn.status_panel}</h2>
                                    <p className="mt-1 text-sm text-slate-500">{tsn.status_panel_subtitle}</p>
                                </div>

                                <div className="space-y-3">
                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.current_status}</div>
                                        <div className="mt-1 text-sm font-semibold text-slate-950">{notice.bid_status_label}</div>
                                        <p className="mt-1 text-sm leading-6 text-slate-600">{guidance.description}</p>
                                        <div className="mt-3 text-sm leading-6 text-slate-500">{guidance.closureRule}</div>
                                    </div>

                                </div>
                            </div>
                        </section>
                    </aside>

                    <main className="space-y-6">
                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="space-y-5">
                                <div>
                                    <h2 className="text-xl font-semibold tracking-tight text-slate-950">{tsn.information}</h2>
                                    <p className="mt-1 text-sm text-slate-500">
                                        {isPrivateRequest
                                            ? tsn.private_request_subtitle
                                            : tsn.notice_subtitle}
                                    </p>
                                </div>

                                {isPrivateRequest ? (
                                    isEditingDeadlines ? (
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.registered}</div>
                                                <div className="text-sm font-medium text-slate-900">
                                                    {notice.saved_at ? formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' }) : '—'}
                                                </div>
                                            </div>
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.contracting_authority}</div>
                                                <div className="text-sm font-medium text-slate-900">{notice.organization_name || notice.buyer_name || '—'}</div>
                                            </div>
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.deadline}</div>
                                                <div className="text-sm font-medium text-slate-900">{notice.deadline ? formatDate(notice.deadline, locale) : tsn.not_registered}</div>
                                            </div>
                                            <div className="space-y-1.5">
                                                <label className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500" htmlFor="reference_number">
                                                    {tsn.reference}
                                                </label>
                                                <input
                                                    id="reference_number"
                                                    type="text"
                                                    value={deadlineForm.data.reference_number}
                                                    onChange={(event) => deadlineForm.setData('reference_number', event.target.value)}
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                />
                                                {deadlineForm.errors.reference_number ? (
                                                    <p className="text-sm text-rose-600">{deadlineForm.errors.reference_number}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <label className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500" htmlFor="contact_person_name">
                                                    {tsn.contact_person}
                                                </label>
                                                <input
                                                    id="contact_person_name"
                                                    type="text"
                                                    value={deadlineForm.data.contact_person_name}
                                                    onChange={(event) => deadlineForm.setData('contact_person_name', event.target.value)}
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                />
                                                {deadlineForm.errors.contact_person_name ? (
                                                    <p className="text-sm text-rose-600">{deadlineForm.errors.contact_person_name}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <label className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500" htmlFor="contact_person_email">
                                                    {tsn.contact_email}
                                                </label>
                                                <input
                                                    id="contact_person_email"
                                                    type="email"
                                                    value={deadlineForm.data.contact_person_email}
                                                    onChange={(event) => deadlineForm.setData('contact_person_email', event.target.value)}
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                />
                                                {deadlineForm.errors.contact_person_email ? (
                                                    <p className="text-sm text-rose-600">{deadlineForm.errors.contact_person_email}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1 md:col-span-2">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.external_link}</div>
                                                <div className="text-sm font-medium text-slate-900">
                                                    {notice.external_url ? (
                                                        <a
                                                            href={notice.external_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="font-semibold text-violet-700 transition hover:text-violet-800"
                                                        >
                                                            {externalLinkLabel}
                                                        </a>
                                                    ) : tsn.not_registered}
                                                </div>
                                            </div>
                                            <div className="space-y-1.5 md:col-span-2">
                                                <label className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500" htmlFor="notes">
                                                    {tsn.notes}
                                                </label>
                                                <textarea
                                                    id="notes"
                                                    value={deadlineForm.data.notes}
                                                    onChange={(event) => deadlineForm.setData('notes', event.target.value)}
                                                    rows={4}
                                                    className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                    placeholder={tsn.notes_placeholder}
                                                />
                                                {deadlineForm.errors.notes ? (
                                                    <p className="text-sm text-rose-600">{deadlineForm.errors.notes}</p>
                                                ) : null}
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.registered}</div>
                                                <div className="text-sm font-medium text-slate-900">
                                                    {notice.saved_at ? formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' }) : '—'}
                                                </div>
                                            </div>
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.contracting_authority}</div>
                                                <div className="text-sm font-medium text-slate-900">{notice.organization_name || notice.buyer_name || '—'}</div>
                                            </div>
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.deadline}</div>
                                                <div className="text-sm font-medium text-slate-900">{notice.deadline ? formatDate(notice.deadline, locale) : tsn.not_registered}</div>
                                            </div>
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.reference}</div>
                                                <div className="text-sm font-medium text-slate-900">{notice.reference_number || tsn.not_registered}</div>
                                            </div>
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.contact_person}</div>
                                                <div className="text-sm font-medium text-slate-900">{notice.contact_person_name || tsn.not_registered}</div>
                                            </div>
                                            <div className="space-y-1">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.contact_email}</div>
                                                <div className="text-sm font-medium text-slate-900">{notice.contact_person_email || tsn.not_registered}</div>
                                            </div>
                                            <div className="space-y-1 md:col-span-2">
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.external_link}</div>
                                                <div className="text-sm font-medium text-slate-900">
                                                    {notice.external_url ? (
                                                        <a
                                                            href={notice.external_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="font-semibold text-violet-700 transition hover:text-violet-800"
                                                        >
                                                            {externalLinkLabel}
                                                        </a>
                                                    ) : tsn.not_registered}
                                                </div>
                                            </div>
                                        </div>
                                    )
                                ) : (
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-1">
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.announcement}</div>
                                            <div className="text-sm font-medium text-slate-900">{notice.notice_id || '—'}</div>
                                        </div>
                                        <div className="space-y-1">
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.contracting_authority}</div>
                                            <div className="text-sm font-medium text-slate-900">{notice.organization_name || '—'}</div>
                                        </div>
                                        <div className="space-y-1">
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.published}</div>
                                            <div className="text-sm font-medium text-slate-900">{formatDate(notice.publication_date, locale)}</div>
                                        </div>
                                        <div className="space-y-1">
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.official_deadline}</div>
                                            <div className="text-sm font-medium text-slate-900">{notice.deadline ? formatDate(notice.deadline, locale) : '—'}</div>
                                        </div>
                                        <div className="space-y-1">
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.cpv}</div>
                                            <div className="text-sm font-medium text-slate-900">{notice.cpv_code || '—'}</div>
                                        </div>
                                    </div>
                                )}

                                {isEditingDeadlines ? (
                                    <div className="rounded-2xl border border-blue-200 bg-blue-50/70 px-4 py-4">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-blue-700">
                                                    {tsn.business_review_title}
                                                </div>
                                                <p className="mt-1 text-sm text-blue-950/75">
                                                    {tsn.business_review_description}
                                                </p>
                                            </div>

                                            <button
                                                type="button"
                                                onClick={addBusinessReview}
                                                className="inline-flex items-center justify-center rounded-xl border border-blue-200 bg-white px-4 py-2 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100"
                                            >
                                                {tsn.business_review_add}
                                            </button>
                                        </div>

                                        <div className="mt-4 space-y-3">
                                            {businessReviews.length > 0 ? (
                                                businessReviews.map((review, index) => (
                                                    <div
                                                        key={review.id ?? `business-review-${index}`}
                                                        className="rounded-2xl border border-blue-200 bg-white px-4 py-4"
                                                    >
                                                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
                                                            <label className="min-w-0 flex-1 space-y-2">
                                                                <span className="text-sm font-medium text-slate-700">
                                                                    {tsn.business_review_item_label} {index + 1}
                                                                </span>
                                                                <input
                                                                    type="date"
                                                                    value={review.business_review_at}
                                                                    onChange={(event) => updateBusinessReviewAt(index, event.target.value)}
                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-100"
                                                                />
                                                                {deadlineForm.errors[`business_reviews.${index}.business_review_at`] ? (
                                                                    <p className="text-sm text-rose-600">
                                                                        {deadlineForm.errors[`business_reviews.${index}.business_review_at`]}
                                                                    </p>
                                                                ) : null}
                                                            </label>

                                                            <button
                                                                type="button"
                                                                onClick={() => removeBusinessReview(index)}
                                                                className="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                            >
                                                                {tsn.delete}
                                                            </button>
                                                        </div>
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="rounded-2xl border border-dashed border-blue-200 bg-white px-4 py-4 text-sm text-blue-900/70">
                                                    {tsn.business_review_empty}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ) : businessReviews.length > 0 ? (
                                    <div className="rounded-2xl border border-blue-200 bg-blue-50/70 px-4 py-4">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-blue-700">
                                            {tsn.business_review_title}
                                        </div>
                                        <div className="mt-3 space-y-3">
                                            {businessReviews.map((review) => (
                                                <div key={review.id} className="flex items-center gap-3 rounded-xl bg-white px-3 py-2.5">
                                                    <span
                                                        className="h-3 w-3 rounded-full bg-blue-700 ring-4 ring-blue-100"
                                                        aria-hidden="true"
                                                    />
                                                    <div className="min-w-0">
                                                        <div className="text-sm font-medium text-slate-900">
                                                            {formatDate(review.business_review_at, locale)}
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ) : null}

                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.summary}</div>
                                    <div className="mt-2 text-sm leading-7 text-slate-700 whitespace-pre-line">
                                        {notice.summary || tsn.no_summary}
                                    </div>
                                </div>

                                {isPrivateRequest && notice.notes && !isEditingDeadlines ? (
                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{tsn.notes}</div>
                                        <div className="mt-2 text-sm leading-7 text-slate-700 whitespace-pre-line">
                                            {notice.notes}
                                        </div>
                                    </div>
                                ) : null}

                                {documents.length > 0 ? (
                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    {frontend.documents}
                                                </div>
                                                <div className="mt-1 text-sm text-slate-500">
                                                    {frontend.document_count
                                                        ? frontend.document_count.replace(':count', String(documents.length))
                                                        : String(documents.length)}
                                                </div>
                                            </div>

                                            {downloadAllUrl && documents.length > 1 ? (
                                                <a
                                                    href={downloadAllUrl}
                                                    className="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                >
                                                    {frontend.download_all}
                                                </a>
                                            ) : null}
                                        </div>

                                        <p className="mt-4 text-sm text-slate-500">
                                            {frontend.download_instruction}
                                        </p>

                                        <div className="mt-4 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                                            {documents.map((document) => (
                                                <div key={document.id} className="grid gap-4 px-4 py-4 md:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))] md:items-center">
                                                    <div className="min-w-0">
                                                        <div className="truncate text-sm font-medium text-slate-950">{document.title}</div>
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            {document.created_at ? formatDate(document.created_at, locale, { hour: '2-digit', minute: '2-digit' }) : '—'}
                                                        </div>
                                                    </div>
                                                    <div className="text-sm text-slate-500">{document.mime_type || common.not_available}</div>
                                                    <div className="text-sm text-slate-500">{formatFileSize(document.file_size)}</div>
                                                    <div className="flex justify-start md:justify-end">
                                                        <a
                                                            href={document.download_url}
                                                            aria-label={`${frontend.download_link_aria_label_prefix ?? frontend.download_link}: ${document.title}`}
                                                            className="inline-flex items-center text-sm font-semibold text-violet-700 underline decoration-violet-300 underline-offset-4 transition hover:text-violet-800"
                                                        >
                                                            {frontend.download_link}
                                                        </a>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ) : null}
                            </div>
                        </section>

                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="space-y-5">
                                {/* Zone 1: Header */}
                                <div className="flex items-center gap-2">
                                    <h2 className="text-xl font-semibold tracking-tight text-slate-950">{tsn.phase_comment_title}</h2>
                                    <InfoHint size="sm" align="right" label={tsn.phase_comment_hint_label ?? 'Vis forklaring for fasekommentarer'} text={tsn.phase_comment_hint} />
                                </div>

                                {/* Zone 2: Phase selector */}
                                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                                    {commentPhaseOptions.map((phaseOption) => {
                                        const isOpen = Boolean(openCommentPhases[phaseOption.status]);
                                        const isCurrentPhase = notice.bid_status === phaseOption.status;
                                        const isHighlighted = isCurrentPhase || isOpen;

                                        return (
                                            <button
                                                key={phaseOption.status}
                                                type="button"
                                                onClick={isCurrentPhase ? undefined : () => toggleCommentPhase(phaseOption.status)}
                                                aria-expanded={isCurrentPhase ? true : isOpen}
                                                disabled={isCurrentPhase}
                                                className={classNames(
                                                    'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-[11px] font-semibold transition disabled:cursor-default',
                                                    isHighlighted
                                                        ? 'border-violet-200 bg-violet-50 text-violet-700'
                                                        : 'border-slate-200 bg-white text-slate-600 hover:border-violet-200 hover:text-violet-700',
                                                )}
                                            >
                                                <span className="text-[10px] font-bold uppercase tracking-[0.14em] opacity-70">
                                                    {phaseOption.number}
                                                </span>
                                                <span className="whitespace-nowrap">
                                                    {phaseOption.label}
                                                </span>
                                                {isCurrentPhase && (
                                                    <span className="rounded-full bg-violet-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.12em] text-violet-700">
                                                        {tsn.active_label}
                                                    </span>
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>

                                {/* Zone 3: Active phase content */}
                                <div className="rounded-2xl border border-slate-200 bg-slate-50">
                                    <button
                                        type="button"
                                        onClick={() => setIsActivePhaseExpanded((v) => !v)}
                                        className="flex w-full items-center justify-between gap-3 px-4 py-4 text-left"
                                    >
                                        <div>
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                {tsn.active_phase_label}
                                            </div>
                                            <div className="mt-1 text-sm font-semibold text-slate-950">
                                                {guidance.phaseTitle}
                                            </div>
                                        </div>
                                        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:text-slate-700">
                                            {isActivePhaseExpanded ? '−' : '+'}
                                        </span>
                                    </button>

                                    {isActivePhaseExpanded && (
                                        <div className="space-y-4 border-t border-slate-200 px-4 pb-4 pt-4">
                                            <div>
                                                <p className="text-sm leading-6 text-slate-600">{guidance.description}</p>
                                                <div className="mt-2 text-xs leading-5 text-slate-500">{guidance.closureRule}</div>
                                            </div>

                                            <div className="space-y-3">
                                                {activePhaseCommentEntries.length > 0 ? (
                                                    activePhaseCommentEntries.map((comment) => (
                                                        <PhaseCommentCard key={comment.id} comment={comment} locale={locale} text={tsn} />
                                                    ))
                                                ) : (
                                                    <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-4 text-sm text-slate-500">
                                                        {tsn.phase_comment_empty}
                                                    </div>
                                                )}
                                            </div>

                                            {canCommentOnCase ? (
                                                <form
                                                    onSubmit={(event) => {
                                                        event.preventDefault();
                                                        submitPhaseComment();
                                                    }}
                                                    className="space-y-3 rounded-2xl border border-slate-200 bg-white px-4 py-4"
                                                >
                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                        {tsn.phase_comment_new}
                                                    </div>

                                                    <label className="space-y-1.5">
                                                        <span className="text-sm font-medium text-slate-800">
                                                            {tsn.phase_comment_form_label}
                                                        </span>
                                                        <textarea
                                                            value={phaseCommentForm.data.comment}
                                                            onChange={(event) => phaseCommentForm.setData('comment', event.target.value)}
                                                            rows={4}
                                                            className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                            placeholder={tsn.phase_comment_placeholder}
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
                                                            {phaseCommentForm.processing ? tsn.phase_comment_saving : tsn.phase_comment_save}
                                                        </button>
                                                    </div>
                                                </form>
                                            ) : null}
                                        </div>
                                    )}
                                </div>

                                {notice.bid_status === 'go_no_go' && goNoGoData ? (
                                    <GoNoGoAssessment
                                        template={goNoGoData.template}
                                        assessment={goNoGoData.assessment}
                                        saveUrl={goNoGoData.save_url}
                                    />
                                ) : null}

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
                                                            {tsn.phase_prefix} {phaseOption.number}
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
                                                        {tsn.phase_comment_hide}
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
                                                        {tsn.phase_comment_empty_for_phase}
                                                    </div>
                                                )}
                                            </section>
                                        );
                                    })}
                                </div>
                            </div>
                        </section>

                        <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="space-y-2">
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <h2 className="text-xl font-semibold tracking-tight text-slate-950">{tsn.info_center_title}</h2>
                                                        <InfoHint size="sm" align="right" label={tsn.info_center_hint_label ?? 'Vis forklaring for Informasjonssenter'} text={tsn.info_center_hint} />
                                                    </div>
                                                    <p className="mt-1 text-sm text-slate-500">{tsn.info_center_description}</p>
                                                </div>
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">
                                        {infoItemSummary}
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={openInfoItemCreator}
                                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                >
                                    {tsn.new_action}
                                </button>
                            </div>

                            {isCreatingInfoItem ? (
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        submitInfoItemCreator();
                                    }}
                                    className="mt-5 space-y-5 rounded-2xl border border-slate-200 bg-slate-50 p-4"
                                >
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <label className="space-y-2 md:col-span-2">
                                            <span className="text-sm font-medium text-slate-700">{tsn.subject_label}</span>
                                            <input
                                                type="text"
                                                value={infoItemForm.data.subject}
                                                onChange={(event) => infoItemForm.setData('subject', event.target.value)}
                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                placeholder={tsn.subject_placeholder}
                                            />
                                            {infoItemForm.errors.subject ? (
                                                <p className="text-sm text-rose-600">{infoItemForm.errors.subject}</p>
                                            ) : null}
                                        </label>

                                        <label className="space-y-2 md:col-span-2">
                                            <span className="text-sm font-medium text-slate-700">{tsn.description_label}</span>
                                            <textarea
                                                value={infoItemForm.data.body}
                                                onChange={(event) => infoItemForm.setData('body', event.target.value)}
                                                rows={5}
                                                className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                placeholder={tsn.description_placeholder}
                                            />
                                            {infoItemForm.errors.body ? (
                                                <p className="text-sm text-rose-600">{infoItemForm.errors.body}</p>
                                            ) : null}
                                        </label>

                                        <label className="space-y-2">
                                            <span className="text-sm font-medium text-slate-700">{tsn.owner_label}</span>
                                            <select
                                                value={infoItemForm.data.owner_user_id}
                                                onChange={(event) => infoItemForm.setData('owner_user_id', event.target.value)}
                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                            >
                                                <option value="">{tsn.no_owner}</option>
                                                {infoItemOwnerOptions.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                            {infoItemForm.errors.owner_user_id ? (
                                                <p className="text-sm text-rose-600">{infoItemForm.errors.owner_user_id}</p>
                                            ) : null}
                                        </label>

                                        <label className="space-y-2">
                                            <span className="text-sm font-medium text-slate-700">{tsn.follow_up_deadline_label}</span>
                                            <input
                                                type="date"
                                                value={infoItemForm.data.response_due_at}
                                                onChange={(event) => infoItemForm.setData('response_due_at', event.target.value)}
                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                            />
                                            {infoItemForm.errors.response_due_at ? (
                                                <p className="text-sm text-rose-600">{infoItemForm.errors.response_due_at}</p>
                                            ) : null}
                                        </label>

                                        <label className="space-y-2">
                                            <span className="text-sm font-medium text-slate-700">{tsn.status_label}</span>
                                            <select
                                                value={infoItemForm.data.status}
                                                onChange={(event) => infoItemForm.setData('status', event.target.value)}
                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                            >
                                                {infoItemStatusOptions.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                            {infoItemForm.errors.status ? (
                                                <p className="text-sm text-rose-600">{infoItemForm.errors.status}</p>
                                            ) : null}
                                        </label>

                                        <label className="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 md:col-span-2">
                                            <input
                                                type="checkbox"
                                                checked={infoItemForm.data.requires_response}
                                                onChange={(event) => infoItemForm.setData('requires_response', event.target.checked)}
                                                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                            />
                                            <div className="space-y-1">
                                                <span className="block text-sm font-medium text-slate-700">{tsn.requires_response_label}</span>
                                                <p className="text-xs text-slate-500">
                                                    {tsn.requires_response_help}
                                                </p>
                                            </div>
                                        </label>
                                    </div>

                                    <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                        <div className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
                                            {tsn.classification_title}
                                        </div>
                                        <p className="mt-1 text-sm text-slate-500">
                                            {tsn.classification_help}
                                        </p>
                                        <div className="mt-4 grid gap-4 md:grid-cols-3">
                                            <label className="space-y-2">
                                                <span className="text-sm font-medium text-slate-700">{tsn.type_label}</span>
                                                <select
                                                    value={infoItemForm.data.type}
                                                    onChange={(event) => infoItemForm.setData('type', event.target.value)}
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                >
                                                    {infoItemTypeOptions.map((option) => (
                                                        <option key={option.value} value={option.value}>
                                                            {option.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {infoItemForm.errors.type ? (
                                                    <p className="text-sm text-rose-600">{infoItemForm.errors.type}</p>
                                                ) : null}
                                            </label>

                                            <label className="space-y-2">
                                                <span className="text-sm font-medium text-slate-700">{tsn.direction_label}</span>
                                                <select
                                                    value={infoItemForm.data.direction}
                                                    onChange={(event) => infoItemForm.setData('direction', event.target.value)}
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                >
                                                    {infoItemDirectionOptions.map((option) => (
                                                        <option key={option.value} value={option.value}>
                                                            {option.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {infoItemForm.errors.direction ? (
                                                    <p className="text-sm text-rose-600">{infoItemForm.errors.direction}</p>
                                                ) : null}
                                            </label>

                                            <label className="space-y-2">
                                                <span className="text-sm font-medium text-slate-700">{tsn.channel_label}</span>
                                                <select
                                                    value={infoItemForm.data.channel}
                                                    onChange={(event) => infoItemForm.setData('channel', event.target.value)}
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                >
                                                    {infoItemChannelOptions.map((option) => (
                                                        <option key={option.value} value={option.value}>
                                                            {option.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {infoItemForm.errors.channel ? (
                                                    <p className="text-sm text-rose-600">{infoItemForm.errors.channel}</p>
                                                ) : null}
                                            </label>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap gap-3">
                                        <button
                                            type="submit"
                                            disabled={infoItemForm.processing || infoItemForm.data.body.trim() === ''}
                                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {infoItemForm.processing ? tsn.save_action_saving : tsn.save_action}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={cancelInfoItemCreator}
                                            disabled={infoItemForm.processing}
                                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {common.cancel}
                                        </button>
                                    </div>
                                </form>
                            ) : null}

                            {infoItemEntries.length > 0 ? (
                                <div className="mt-5 space-y-3">
                                    {infoItemEntries.map((item) => {
                                        const isClosed = item.status === 'closed';
                                        const canCloseItem = Boolean(item.can_close) && !isClosed;
                                        const isCloseFormOpen = closingInfoItemId === item.id;

                                        return (
                                            <article key={item.id} className="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                                    <div className="min-w-0 space-y-3">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <StatusBadge tone={infoItemStatusBadgeTone(item.status)}>
                                                                {item.status_label}
                                                            </StatusBadge>
                                                            <StatusBadge tone="slate">{item.type_label}</StatusBadge>
                                                            <StatusBadge tone="slate">{item.direction_label}</StatusBadge>
                                                            <StatusBadge tone="slate">{item.channel_label}</StatusBadge>
                                                        </div>

                                                        <div className="text-sm font-semibold text-slate-950">
                                                            {item.subject || tsn.no_subject}
                                                        </div>

                                                        <div className="whitespace-pre-line text-sm leading-6 text-slate-700">
                                                            {item.body}
                                                        </div>

                                                        <div className="flex flex-wrap gap-2 text-xs text-slate-500">
                                                            {item.owner ? (
                                                                <span className="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                                                                    {tsn.owner_prefix} {item.owner.name}
                                                                </span>
                                                            ) : null}
                                                            {item.status !== 'closed' && item.requires_response ? (
                                                                <span className="rounded-full bg-amber-50 px-2.5 py-1 font-medium text-amber-800">
                                                                    {tsn.awaiting_response}
                                                                </span>
                                                            ) : null}
                                                            {item.response_due_at ? (
                                                                <span className="rounded-full bg-violet-50 px-2.5 py-1 font-medium text-violet-700">
                                                                    {tsn.follow_up_deadline_prefix} {formatDate(item.response_due_at, locale)}
                                                                </span>
                                                            ) : null}
                                                            {item.closed_at ? (
                                                                <span className="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                                                                    {tsn.closed_prefix} {formatDate(item.closed_at, locale, { hour: '2-digit', minute: '2-digit' })}
                                                                </span>
                                                            ) : null}
                                                        </div>

                                                        {isClosed && item.closure_comment ? (
                                                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                    {tsn.close_comment_label}
                                                                </div>
                                                                <div className="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">
                                                                    {item.closure_comment}
                                                                </div>
                                                            </div>
                                                        ) : null}
                                                    </div>

                                                    <div className="shrink-0 space-y-2 text-xs text-slate-500 lg:text-right">
                                                        <div className="font-medium text-slate-700">
                                                            {tsn.created_prefix} {formatDate(item.created_at, locale, { hour: '2-digit', minute: '2-digit' })}
                                                        </div>
                                                        <div>
                                                            {item.created_by ? `${tsn.by_prefix} ${item.created_by.name}` : `${tsn.by_prefix} —`}
                                                        </div>

                                                        {canCloseItem ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => openInfoItemCloser(item)}
                                                                className="inline-flex min-h-8 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100"
                                                            >
                                                                {isCloseFormOpen ? tsn.close_action_hide : tsn.close_action}
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                </div>

                                                {isCloseFormOpen ? (
                                                    <form
                                                        onSubmit={(event) => {
                                                            event.preventDefault();
                                                            submitInfoItemCloser(item);
                                                        }}
                                                        className="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-4"
                                                    >
                                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                            <div>
                                                                <div className="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">
                                                                    {tsn.close_action_title}
                                                                </div>
                                                                <p className="mt-1 text-sm text-slate-600">
                                                                    {tsn.close_action_help}
                                                                </p>
                                                            </div>

                                                            <button
                                                                type="button"
                                                                onClick={cancelInfoItemCloser}
                                                                className="inline-flex min-h-8 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                {common.cancel}
                                                            </button>
                                                        </div>

                                                        <label className="mt-4 block space-y-2">
                                                            <span className="text-sm font-medium text-slate-700">{tsn.close_action_comment_label}</span>
                                                            <textarea
                                                                value={closeInfoItemForm.data.closure_comment}
                                                                onChange={(event) => closeInfoItemForm.setData('closure_comment', event.target.value)}
                                                                rows={3}
                                                                className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-emerald-300 focus:ring-4 focus:ring-emerald-100"
                                                                placeholder={tsn.close_action_placeholder}
                                                            />
                                                            <p className="text-xs leading-5 text-slate-500">
                                                                {tsn.close_action_note}
                                                            </p>
                                                            {closeInfoItemForm.errors.closure_comment ? (
                                                                <p className="text-sm text-rose-600">{closeInfoItemForm.errors.closure_comment}</p>
                                                            ) : null}
                                                        </label>

                                                        <div className="mt-4 flex flex-wrap gap-3">
                                                            <button
                                                                type="submit"
                                                                disabled={closeInfoItemForm.processing}
                                                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {closeInfoItemForm.processing ? tsn.close_action_saving : tsn.close_action_save}
                                                            </button>
                                                        </div>
                                                    </form>
                                                ) : null}
                                            </article>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="mt-5 rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                                    {tsn.no_actions_yet}
                                </div>
                            )}
                        </section>

                        {shouldShowSubmissions ? (
                            <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h2 className="text-xl font-semibold tracking-tight text-slate-950">{tsn.submissions_title}</h2>
                                        <p className="mt-1 text-sm text-slate-500">{tsn.submissions_subtitle}</p>
                                    </div>
                                </div>

                                {notice.submissions.length === 0 ? (
                                        <div className="mt-5 rounded-2xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                                        {tsn.no_submissions}
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
                                                            {tsn.round_prefix} {submission.sequence_number}
                                                        </div>
                                                    </div>

                                                    <div
                                                        className={classNames(
                                                            'text-sm font-medium',
                                                            submission.submitted_at ? 'text-slate-900' : 'text-slate-500',
                                                        )}
                                                    >
                                                        {submission.submitted_at ? formatDate(submission.submitted_at, locale, { hour: '2-digit', minute: '2-digit' }) : tsn.not_registered}
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
                                    <h2 className="text-lg font-semibold tracking-tight text-slate-950">{tsn.actions_title}</h2>
                                    <p className="mt-1 text-sm text-slate-500">{tsn.actions_subtitle}</p>
                                </div>

                                <div className="space-y-3">
                                    <ActionAccordionSection
                                        title={tsn.next_decision_title}
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
                                                    {statusActionLabel(primaryAction.status, tsn)}
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
                                                            {statusActionLabel(action.status, tsn)}
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
                                                {closureActionGuidance(activeClosureAction.status, tsn) ? (
                                                    <div className={classNames('rounded-xl border px-3 py-3 text-sm font-medium', closureActionGuidance(activeClosureAction.status, tsn)?.className)}>
                                                        {closureActionGuidance(activeClosureAction.status, tsn)?.text}
                                                    </div>
                                                ) : null}

                                                <div className="space-y-1.5">
                                                    <label className="text-sm font-medium text-slate-800" htmlFor="bid_closure_reason">
                                                        {tsn.close_reason_label}
                                                    </label>
                                                    <select
                                                        id="bid_closure_reason"
                                                        value={statusForm.data.bid_closure_reason}
                                                        onChange={(event) => statusForm.setData('bid_closure_reason', event.target.value)}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                    >
                                                        <option value="">{tsn.close_reason_placeholder}</option>
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
                                                        {tsn.close_note_label}
                                                    </label>
                                                    <textarea
                                                        id="bid_closure_note"
                                                        value={statusForm.data.bid_closure_note}
                                                        onChange={(event) => statusForm.setData('bid_closure_note', event.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                        placeholder={tsn.close_note_placeholder}
                                                    />
                                                </div>

                                                <div className="flex flex-wrap gap-3">
                                                    <button
                                                        type="submit"
                                                        disabled={isStatusActionProcessing}
                                                        className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {isStatusActionProcessing ? tsn.updating : statusActionLabel(activeClosureAction.status, tsn)}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={resetStatusForm}
                                                        disabled={isStatusActionProcessing}
                                                        className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {common.cancel}
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
                                                {submissionForm.processing ? tsn.registering : tsn.add_submission}
                                            </button>
                                        ) : null}
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title={tsn.bid_manager_section_title}
                                        summary={bidManagerSummary}
                                        hint={tsn.bid_manager_section_hint}
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
                                                        {tsn.bid_manager_section_title}
                                                    </label>
                                                    <select
                                                        id="bid_manager_user_id"
                                                        name="bid_manager_user_id"
                                                        value={bidManagerForm.data.bid_manager_user_id}
                                                        onChange={(event) => bidManagerForm.setData('bid_manager_user_id', event.target.value)}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                    >
                                                        <option value="">{tsn.not_set}</option>
                                                        {bidManagerOptions.map((option) => (
                                                            <option key={option.value} value={option.value}>
                                                                {option.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <p className="text-xs text-slate-400">
                                                        {tsn.bid_manager_help}
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
                                                    {bidManagerForm.processing ? tsn.bid_manager_saving : tsn.bid_manager_save}
                                                </button>
                                            </form>
                                        ) : null}
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title={tsn.opportunity_owner_section_title}
                                        summary={opportunityOwnerSummary}
                                        hint={tsn.opportunity_owner_section_hint}
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
                                                        {tsn.opportunity_owner_section_title}
                                                    </label>
                                                    <select
                                                        id="opportunity_owner_user_id"
                                                        name="opportunity_owner_user_id"
                                                        value={opportunityOwnerForm.data.opportunity_owner_user_id}
                                                        onChange={(event) => opportunityOwnerForm.setData('opportunity_owner_user_id', event.target.value)}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                    >
                                                        <option value="">{tsn.not_set}</option>
                                                        {opportunityOwnerOptions.map((option) => (
                                                            <option key={option.value} value={option.value}>
                                                                {option.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <p className="text-xs text-slate-400">
                                                        {tsn.opportunity_owner_help}
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
                                                    {opportunityOwnerForm.processing ? tsn.opportunity_owner_saving : tsn.opportunity_owner_save}
                                                </button>
                                            </form>
                                        ) : null}
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title={tsn.case_access_section_title}
                                        summary={caseAccessSummary}
                                        hint={tsn.case_access_section_hint}
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
                                                                            {access.user?.name || tsn.unknown_user}
                                                                        </div>
                                                                        <div className="text-xs text-slate-500">
                                                                            {access.user?.email || '—'}
                                                                        </div>
                                                                    </div>

                                                                    <div className="flex flex-wrap gap-2 text-xs text-slate-500">
                                                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                                                                            {accessRoleLabel(access.access_role, tsn)}
                                                                        </span>
                                                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700">
                                                                            {tsn.access_granted_by} {access.granted_by?.name || '—'}
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
                                                                        {tsn.access_revoke}
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : (
                                                <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">
                                                    {tsn.case_access_empty}
                                                </div>
                                            )}

                                            {canManageCaseAccess ? (
                                                <form onSubmit={grantCaseAccess} className="space-y-4 border-t border-slate-200 pt-4">
                                                    <div className="grid gap-4">
                                                        <div className="space-y-1.5">
                                                            <label className="text-sm font-medium text-slate-800" htmlFor="case_access_user_id">
                                                                {tsn.case_access_user_label}
                                                            </label>
                                                            <select
                                                                id="case_access_user_id"
                                                                name="user_id"
                                                                value={caseAccessForm.data.user_id}
                                                                onChange={(event) => caseAccessForm.setData('user_id', event.target.value)}
                                                                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                                            >
                                                                <option value="">{tsn.case_access_user_placeholder}</option>
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
                                                                {tsn.case_access_role_label}
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
                                                                        {accessRoleLabel(option.value, tsn)}
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
                                                            {caseAccessForm.processing ? tsn.case_access_saving : tsn.case_access_save}
                                                        </button>
                                                    </div>
                                                </form>
                                            ) : (
                                                <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">
                                                    {tsn.case_access_no_permission}
                                                </div>
                                            )}
                                        </div>
                                    </ActionAccordionSection>

                                    <ActionAccordionSection
                                        title={tsn.administration_title}
                                        summary={administrationSummary}
                                        hint={tsn.administration_hint}
                                        isOpen={openActionSection === 'administration'}
                                        onToggle={() => setOpenActionSection((current) => (current === 'administration' ? null : 'administration'))}
                                    >
                                        {!notice.archived_at && canArchiveNotice ? (
                                            isArchiveHistoryFormOpen ? (
                                                <form
                                                    onSubmit={submitArchiveSavedNotice}
                                                    className="rounded-2xl border border-slate-200 bg-slate-50 p-4"
                                                >
                                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                        <div>
                                                            <div className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
                                                                {tsn.archive_move_title}
                                                            </div>
                                                            <p className="mt-1 text-sm text-slate-600">
                                                                {tsn.archive_move_help}
                                                            </p>
                                                        </div>

                                                        <button
                                                            type="button"
                                                            onClick={cancelArchiveSavedNoticeForm}
                                                            className="inline-flex min-h-8 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                        >
                                                            {common.cancel}
                                                        </button>
                                                    </div>

                                                    <label className="mt-4 block space-y-2">
                                                        <span className="text-sm font-medium text-slate-700">{tsn.archive_type_label}</span>
                                                        <select
                                                            value={archiveHistoryForm.data.history_type}
                                                            onChange={(event) => archiveHistoryForm.setData('history_type', event.target.value)}
                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        >
                                                            <option value="">{tsn.archive_type_placeholder}</option>
                                                            {historyTypeOptions.map((option) => (
                                                                <option key={option.value} value={option.value}>
                                                                    {option.label}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {archiveHistoryForm.errors.history_type ? (
                                                            <p className="text-sm text-rose-600">{archiveHistoryForm.errors.history_type}</p>
                                                        ) : null}
                                                    </label>

                                                    <div className="mt-4 flex flex-wrap gap-3">
                                                        <button
                                                            type="button"
                                                            onClick={submitArchiveSavedNotice}
                                                            disabled={archiveHistoryForm.processing || archiveHistoryForm.data.history_type.trim() === ''}
                                                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            {archiveHistoryForm.processing ? tsn.archive_saving : tsn.archive_save}
                                                        </button>
                                                    </div>
                                                </form>
                                            ) : (
                                                <div className="flex flex-wrap gap-3">
                                                    <button
                                                        type="button"
                                                        onClick={openArchiveSavedNoticeForm}
                                                        className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                    >
                                                        {tsn.archive_case_action}
                                                    </button>
                                                </div>
                                            )
                                        ) : !notice.archived_at ? (
                                            <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">
                                                {tsn.archive_not_allowed}
                                            </div>
                                        ) : null}
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
