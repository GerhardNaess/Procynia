import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CpvSelector from './CpvSelector';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import AlertBox from '../../../Components/App/AlertBox';
import DiscoveryNoticeCard from '../../../Components/App/DiscoveryNoticeCard';
import PageHelpButton from '../../../Components/App/PageHelpButton';


const noticeSummaryPreviewLimit = 280;
const noticeSummaryCollapsedStyle = {
    display: '-webkit-box',
    WebkitBoxOrient: 'vertical',
    WebkitLineClamp: 4,
    overflow: 'hidden',
};

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function bidStatusBadgeClassName(status) {
    switch (status) {
        case 'qualifying':
            return 'bg-sky-100 text-sky-700 ring-sky-200';
        case 'go_no_go':
            return 'bg-amber-50 text-amber-700 ring-amber-200';
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
            return 'bg-amber-100 text-amber-800 ring-amber-200';
        case 'archived':
            return 'bg-slate-200 text-slate-700 ring-slate-300';
        default:
            return 'bg-slate-100 text-slate-700 ring-slate-200';
    }
}

function buildNoticeQuery(values) {
    return Object.fromEntries(
        Object.entries(values).filter(([, value]) => {
            if (value === null || value === undefined) {
                return false;
            }

            if (typeof value === 'string' && value.trim() === '') {
                return false;
            }

            return true;
        }),
    );
}

function SearchIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M9 15a6 6 0 1 1 0-12 6 6 0 0 1 0 12Z" />
            <path d="m13.5 13.5 4 4" strokeLinecap="round" />
        </svg>
    );
}

function FilterIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M3 5h14" strokeLinecap="round" />
            <path d="M6 10h8" strokeLinecap="round" />
            <path d="M8.5 15h3" strokeLinecap="round" />
        </svg>
    );
}

function BookmarkIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M6 3.5h8a1 1 0 0 1 1 1V17l-5-3-5 3V4.5a1 1 0 0 1 1-1Z" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function BuildingIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M3.5 16.5h13" strokeLinecap="round" />
            <path d="M5 16V5.5a1 1 0 0 1 1-1h4v11.5" strokeLinejoin="round" />
            <path d="M10 16V8.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V16" strokeLinejoin="round" />
        </svg>
    );
}

function CalendarIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M6 3.5v2" strokeLinecap="round" />
            <path d="M14 3.5v2" strokeLinecap="round" />
            <path d="M4 7h12" strokeLinecap="round" />
            <rect x="4" y="5" width="12" height="11" rx="2" />
        </svg>
    );
}

function ClockIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <circle cx="10" cy="10" r="6.5" />
            <path d="M10 7v3.5l2.5 1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function formatDate(value, locale, options = {}) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...options,
    }).format(new Date(value));
}

function formatDeadlineDate(value) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('nb-NO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
}

function formatMnokValue(value, locale) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return `${new Intl.NumberFormat(locale, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(Number(value))} MNOK`;
}

function dateInputValue(value) {
    if (!value) {
        return '';
    }

    return String(value).slice(0, 10);
}

function publicationDateRangeFromPeriod(period) {
    const days = Number.parseInt(String(period ?? ''), 10);

    if (!Number.isFinite(days) || days <= 0) {
        return {
            from: '',
            to: '',
        };
    }

    const to = new Date();
    const from = new Date(to.getTime());

    from.setDate(from.getDate() - days);

    return {
        from: from.toISOString().slice(0, 10),
        to: to.toISOString().slice(0, 10),
    };
}

function publicationDateRangeFromFilters(filters) {
    const from = typeof filters?.publication_date_from === 'string' ? filters.publication_date_from : '';
    const to = typeof filters?.publication_date_to === 'string' ? filters.publication_date_to : '';

    if (from !== '' || to !== '') {
        return {
            from,
            to,
        };
    }

    if (typeof filters?.publication_period === 'string' && filters.publication_period.trim() !== '') {
        return publicationDateRangeFromPeriod(filters.publication_period);
    }

    return {
        from: '',
        to: '',
    };
}

function formatNumberWithSpaces(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const numeric = value.toString().replace(/\s/g, '');

    return numeric.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

function parseNumberFromSpaces(value) {
    return (value ?? '').replace(/\s/g, '');
}

function addMonthsNoOverflow(date, months) {
    const baseDate = new Date(date.getTime());
    const target = new Date(baseDate.getFullYear(), baseDate.getMonth() + months, 1);
    const lastDayOfTargetMonth = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

    target.setDate(Math.min(baseDate.getDate(), lastDayOfTargetMonth));
    target.setHours(baseDate.getHours(), baseDate.getMinutes(), baseDate.getSeconds(), baseDate.getMilliseconds());

    return target;
}

function historyNextFollowUpPreviewDate(offsetMonths) {
    const normalized = Number.parseInt(String(offsetMonths ?? ''), 10);

    if (!Number.isFinite(normalized) || normalized <= 0) {
        return null;
    }

    return addMonthsNoOverflow(new Date(), normalized);
}

function estimateFollowUpOffsetMonthsFromDate(nextProcessDateAt) {
    if (!nextProcessDateAt) {
        return '';
    }

    const targetDate = new Date(nextProcessDateAt);

    if (Number.isNaN(targetDate.getTime())) {
        return '';
    }

    const today = new Date();
    const baseDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const normalizedTargetDate = new Date(targetDate.getFullYear(), targetDate.getMonth(), targetDate.getDate());

    if (normalizedTargetDate <= baseDate) {
        return '';
    }

    let months = ((normalizedTargetDate.getFullYear() - baseDate.getFullYear()) * 12) + (normalizedTargetDate.getMonth() - baseDate.getMonth());

    if (addMonthsNoOverflow(baseDate, months) < normalizedTargetDate) {
        months += 1;
    }

    return months > 0 ? String(months) : '';
}

function isHistoryProcurementType(value) {
    return value === 'one_time' || value === 'recurring';
}

function isHistoryFollowUpMode(value) {
    return value === 'none' || value === 'manual_offset' || value === 'contract_end';
}

function historyMonthLabel(months, text) {
    return `${months} ${Number(months) === 1 ? text.monthSingular : text.monthPlural}`;
}

function deriveHistorySelection(notice) {
    if (isHistoryProcurementType(notice.procurement_type) && isHistoryFollowUpMode(notice.follow_up_mode)) {
        return {
            procurementType: notice.procurement_type,
            followUpMode: notice.follow_up_mode,
        };
    }

    if (notice.contract_period_months !== null && notice.contract_period_months !== undefined) {
        return {
            procurementType: 'recurring',
            followUpMode: 'contract_end',
        };
    }

    return {
        procurementType: '',
        followUpMode: '',
    };
}

function buildHistoryFormData(notice) {
    const selection = deriveHistorySelection(notice);
    const legacyOffsetMonths = selection.followUpMode === 'contract_end'
        ? estimateFollowUpOffsetMonthsFromDate(notice.next_process_date_at)
        : '';
    const normalizedFollowUpMode = selection.followUpMode === 'contract_end'
        ? (legacyOffsetMonths !== '' ? 'manual_offset' : 'none')
        : selection.followUpMode;

    return {
        selected_supplier_name: notice.selected_supplier_name ?? '',
        contract_value_mnok: notice.contract_value_mnok !== null && notice.contract_value_mnok !== undefined ? String(notice.contract_value_mnok) : '',
        procurement_type: selection.procurementType,
        follow_up_mode: normalizedFollowUpMode,
        follow_up_offset_months: selection.followUpMode === 'contract_end'
            ? legacyOffsetMonths
            : (notice.follow_up_offset_months !== null && notice.follow_up_offset_months !== undefined ? String(notice.follow_up_offset_months) : ''),
        contract_period_months: notice.contract_period_months !== null && notice.contract_period_months !== undefined ? String(notice.contract_period_months) : '',
    };
}

function historyContractSummary(notice, text) {
    const selection = deriveHistorySelection(notice);

    if (selection.procurementType === 'recurring' && notice.contract_period_months) {
        return `${text.contractPeriodPrefix}: ${historyMonthLabel(notice.contract_period_months, text)}`;
    }

    if (notice.contract_period_text) {
        return `${text.contractPeriodPrefix}: ${notice.contract_period_text}`;
    }

    return null;
}

function historyNeedsStructuredSelection(notice) {
    const selection = deriveHistorySelection(notice);

    return selection.procurementType === '' && selection.followUpMode === '' && Boolean(notice.contract_period_text || notice.next_process_date_at);
}

function normalizeCount(value) {
    const normalized = Number(value);

    return Number.isFinite(normalized) ? normalized : 0;
}

function formatInteger(value, locale) {
    return new Intl.NumberFormat(locale).format(normalizeCount(value));
}

function summarizeText(value, text) {
    const trimmed = (value ?? '').trim();

    if (trimmed === '') {
        return text.summaryFallback;
    }

    return trimmed;
}

function statusBadge(status, deadline, text) {
    if (status) {
        return {
            label: status,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (!deadline) {
        return {
            label: text.defaultStatusLabel,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (new Date(deadline) <= new Date()) {
        return {
            label: text.deadlineExpiredLabel,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        };
    }

    return {
        label: text.activeLabel,
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    };
}

function noticeSourceTypeLabel(notice, text) {
    if (notice.source_type_label) {
        return notice.source_type_label;
    }

    return notice.source_type === 'private_request'
        ? text.privateRequestSourceLabel
        : text.publicNoticeSourceLabel;
}

function noticeSourceTimelineLabel(notice, locale, text) {
    if (notice.source_type === 'private_request') {
        return `${text.sourceRegisteredLabel} ${formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' })}`;
    }

    return `${text.sourcePublishedLabel} ${formatDate(notice.publication_date, locale)}`;
}

function noticeExternalLinkLabel(notice, text) {
    return notice.source_type === 'private_request'
        ? text.openLinkLabel
        : text.openInDoffinLabel;
}

function noticeSourceBadgeClassName(notice) {
    return notice.source_type === 'private_request'
        ? 'bg-violet-100 text-violet-700 ring-violet-200'
        : 'bg-slate-100 text-slate-700 ring-slate-200';
}

function savedNoticeDeadlineBadge(notice, locale, text) {
    if (notice.source_type === 'private_request') {
        if (!notice.deadline) {
            return {
                label: text.deadlineNotRegisteredLabel,
                className: 'bg-slate-100 text-slate-700 ring-slate-200',
            };
        }

        if (new Date(notice.deadline) <= new Date()) {
            return {
                label: text.deadlineExpiredLabel,
                className: 'bg-rose-100 text-rose-700 ring-rose-200',
            };
        }

        return {
            label: `${text.deadlinePrefix} ${formatDate(notice.deadline, locale)}`,
            className: 'bg-violet-100 text-violet-700 ring-violet-200',
        };
    }

    if (notice.deadline_state === 'upcoming' && notice.next_deadline_type && notice.next_deadline_at) {
        return {
            label: `${text.deadlinePrefix} ${notice.next_deadline_type}: ${formatDeadlineDate(notice.next_deadline_at)}`,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (notice.deadline_state === 'expired') {
        return {
            label: text.deadlineExpiredLabel,
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        };
    }

    return {
        label: text.deadlineMissingMetadataLabel,
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    };
}

function savedNoticeTimelineSteps(notice, text) {
    return [
        { key: 'questions_rfi', label: text.questionsRfiLabel, date: notice.questions_rfi_deadline_at },
        { key: 'rfi', label: text.rfiLabel, date: notice.rfi_submission_deadline_at },
        { key: 'questions_rfp', label: text.questionsRfpLabel, date: notice.questions_rfp_deadline_at },
        { key: 'rfp', label: text.rfpLabel, date: notice.deadline },
        { key: 'award', label: text.awardLabel, date: notice.award_date_at },
    ];
}

function privateRequestSummaryFields(notice, locale, text) {
    return [
        {
            key: 'saved_at',
            label: text.registeredLabel,
            value: notice.saved_at ? formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' }) : text.notRegistered,
        },
        {
            key: 'buyer_name',
            label: text.buyerLabel,
            value: notice.buyer_name || text.notRegistered,
        },
        {
            key: 'deadline',
            label: text.deadlineLabel,
            value: notice.deadline ? formatDate(notice.deadline, locale) : text.notRegistered,
        },
        {
            key: 'reference_number',
            label: text.referenceLabel,
            value: notice.reference_number || text.notRegistered,
        },
        {
            key: 'contact_person_name',
            label: text.contactPersonLabel,
            value: notice.contact_person_name || text.notRegistered,
        },
        {
            key: 'contact_person_email',
            label: text.contactEmailLabel,
            value: notice.contact_person_email || text.notRegistered,
        },
        {
            key: 'notes',
            label: text.notesLabel,
            value: notice.notes || text.noNotesRegistered,
            span: true,
        },
    ];
}

function emptyStateContent(mode, hasAppliedSearch, hasAppliedRefinements, totalHits = 0, visibleHits = 0, liveSearchError = '', text) {
    if (mode === 'live' && liveSearchError.trim() !== '') {
        return {
            title: text.noResultsLiveSearchErrorTitle,
            body: liveSearchError,
        };
    }

    if (mode === 'saved') {
        return {
            title: text.noResultsSavedTitle,
            body: text.noResultsSavedBody,
        };
    }

    if (mode === 'history') {
        return {
            title: text.noResultsHistoryTitle,
            body: text.noResultsHistoryBody,
        };
    }

    if (totalHits > 0 && visibleHits === 0) {
        return {
            title: text.noResultsFilteredTitle,
            body: text.noResultsFilteredBody,
        };
    }

    return {
        title: hasAppliedSearch || hasAppliedRefinements
            ? text.noResultsLiveTitle
            : text.noResultsLiveTitle,
        body: hasAppliedRefinements
            ? text.noResultsFilteredBody
            : text.noResultsLiveBody,
    };
}

export default function NoticeIndex({
    notices,
    filters,
    savedSearches = [],
    source,
    supportMode,
    cpvSelector,
    historyTypeOptions = [],
    mode = 'live',
    tab = '',
    worklist = {},
    watchAlerts = {},
}) {
    const { locale, translations } = usePage().props;
    const tf = translations?.frontend ?? {};
    const common = translations?.common ?? {};
    const navigation = translations?.navigation ?? {};
    const nt = translations?.notices ?? {};
    const noticesCardText = nt.card ?? {};
    const noticesText = {
        pageSubtitleSaved: nt.page_subtitle_saved,
        pageSubtitleHistory: nt.page_subtitle_history,
        summaryFallback: nt.summary_fallback,
        monthSingular: nt.month_singular,
        monthPlural: nt.month_plural,
        contractPeriodPrefix: nt.contract_period_prefix,
        defaultStatusLabel: nt.default_status_label,
        activeLabel: nt.active_label,
        deadlineExpiredLabel: nt.deadline_expired_label,
        deadlineNotRegisteredLabel: nt.deadline_not_registered_label,
        deadlineMissingMetadataLabel: nt.deadline_missing_metadata_label,
        deadlinePrefix: nt.deadline_prefix,
        privateRequestSourceLabel: nt.private_request_source_label,
        publicNoticeSourceLabel: nt.public_notice_source_label,
        typePrefix: nt.type_prefix,
        sourceRegisteredLabel: nt.source_registered_label,
        sourcePublishedLabel: nt.source_published_label,
        openLinkLabel: nt.open_link_label,
        openInDoffinLabel: nt.open_in_doffin_label,
        externalLinkLabel: nt.external_link_label,
        questionsRfiLabel: nt.questions_rfi_label,
        rfiLabel: nt.rfi_label,
        questionsRfpLabel: nt.questions_rfp_label,
        rfpLabel: nt.rfp_label,
        awardLabel: nt.award_label,
        deadlineQuestionsRfiLabel: nt.deadline_questions_rfi_label,
        deadlineRfiSubmissionLabel: nt.deadline_rfi_submission_label,
        deadlineQuestionsRfpLabel: nt.deadline_questions_rfp_label,
        deadlineRfpSubmissionLabel: nt.deadline_rfp_submission_label,
        deadlineAwardDateLabel: nt.deadline_award_date_label,
        registeredLabel: nt.registered_label,
        buyerLabel: nt.buyer_label,
        deadlineLabel: nt.deadline_label,
        referenceLabel: nt.reference_label,
        contactPersonLabel: nt.contact_person_label,
        contactEmailLabel: nt.contact_email_label,
        notesLabel: nt.notes_label,
        notRegistered: nt.not_registered,
        noNotesRegistered: nt.no_notes_registered,
        savedByLabel: nt.saved_by_label,
        savedLabel: nt.saved_label,
        selectedSupplierLabel: nt.selected_supplier_label,
        contractValueLabel: nt.contract_value_label,
        procurementTypeLabel: nt.procurement_type_label,
        selectProcurementType: nt.select_procurement_type,
        followUpLabel: nt.follow_up_label,
        followUpOffsetMonthsLabel: nt.follow_up_offset_months_label,
        contractPeriodMonthsLabel: nt.contract_period_months_label,
        contractPeriodHelp: nt.contract_period_help,
        nextFollowUpTitle: nt.next_follow_up_title,
        nextFollowUpManualHint: nt.next_follow_up_manual_hint,
        nextFollowUpManualHelp: nt.next_follow_up_manual_help,
        noPlannedFollowUp: nt.no_planned_follow_up,
        noResultsLiveSearchErrorTitle: nt.no_results_live_search_error_title,
        noResultsSavedTitle: nt.no_results_saved_title,
        noResultsSavedBody: nt.no_results_saved_body,
        noResultsHistoryTitle: nt.no_results_history_title,
        noResultsHistoryBody: nt.no_results_history_body,
        noResultsFilteredTitle: nt.no_results_filtered_title,
        noResultsFilteredBody: nt.no_results_filtered_body,
        noResultsLiveTitle: nt.no_results_live_title,
        noResultsLiveBody: nt.no_results_live_body,
        liveSearchErrorTitle: nt.live_search_error_title,
        liveSearchHitsTitle: nt.live_search_hits_title,
        liveCappedWarning: nt.live_capped_warning,
        liveFallbackBanner: nt.live_fallback_banner,
        alertsTitle: nt.alerts_title,
        alertsEmpty: nt.alerts_empty,
        alertsDeleteLabel: nt.alerts_delete_label,
        alertsDeleteConfirm: nt.alerts_delete_confirm,
        alertsOpenDoffin: nt.alerts_open_doffin,
        liveTitle: nt.live_title,
        liveDescription: nt.live_description,
        liveSearchPlaceholder: nt.live_search_placeholder,
        filtersDescription: nt.filters_description,
        watchListPlaceholder: nt.watch_list_placeholder,
        watchListPlaceholderEmpty: nt.watch_list_placeholder_empty,
        watchListActiveTitle: nt.watch_list_active_title,
        watchListActiveDescription: nt.watch_list_active_description,
        watchListEmptyHelp: nt.watch_list_empty_help,
        watchListHelp: nt.watch_list_help,
        organizationPlaceholder: nt.organization_placeholder,
        keywordsPlaceholder: nt.keywords_placeholder,
        keywordsHelp: nt.keywords_help,
        publicationDateLabel: nt.publication_date_label,
        fromDateLabel: nt.from_date_label,
        toDateLabel: nt.to_date_label,
        relevanceDisabledHelp: nt.relevance_disabled_help,
        privateRequestTitle: nt.private_request_title,
        privateRequestDescription: nt.private_request_description,
        privateRequestToggleHide: nt.private_request_toggle_hide,
        privateRequestToggleShow: nt.private_request_toggle_show,
        privateRequestFormHidden: nt.private_request_form_hidden,
        privateRequestFieldTitle: nt.private_request_field_title,
        privateRequestPlaceholderTitle: nt.private_request_placeholder_title,
        privateRequestFieldBuyerName: nt.private_request_field_buyer_name,
        privateRequestPlaceholderBuyerName: nt.private_request_placeholder_buyer_name,
        privateRequestFieldSummary: nt.private_request_field_summary,
        privateRequestPlaceholderSummary: nt.private_request_placeholder_summary,
        privateRequestFieldReference: nt.private_request_field_reference,
        privateRequestPlaceholderReference: nt.private_request_placeholder_reference,
        privateRequestFieldContactPerson: nt.private_request_field_contact_person,
        privateRequestPlaceholderContactPerson: nt.private_request_placeholder_contact_person,
        privateRequestFieldContactEmail: nt.private_request_field_contact_email,
        privateRequestPlaceholderContactEmail: nt.private_request_placeholder_contact_email,
        privateRequestFieldExternalUrl: nt.private_request_field_external_url,
        privateRequestPlaceholderExternalUrl: nt.private_request_placeholder_external_url,
        privateRequestFieldNotes: nt.private_request_field_notes,
        privateRequestPlaceholderNotes: nt.private_request_placeholder_notes,
        privateRequestSaving: nt.private_request_saving,
        privateRequestSubmit: nt.private_request_submit,
        privateRequestReset: nt.private_request_reset,
        worklistClearFilter: nt.worklist_clear_filter,
        worklistFilterAllTypes: nt.worklist_filter_all_types,
        worklistFilterTitleHistory: nt.worklist_filter_title_history,
        worklistFilterTitleSaved: nt.worklist_filter_title_saved,
        worklistFilterDescriptionHistory: nt.worklist_filter_description_history,
        worklistFilterDescriptionSaved: nt.worklist_filter_description_saved,
        worklistFilterLabelHistory: nt.worklist_filter_label_history,
        worklistFilterLabelSaved: nt.worklist_filter_label_saved,
        businessReviewTitle: nt.business_review_title,
        businessReviewDescription: nt.business_review_description,
        businessReviewAdd: nt.business_review_add,
        businessReviewItemLabel: nt.business_review_item_label,
        businessReviewEmpty: nt.business_review_empty,
        privateRequestSectionDescription: nt.private_request_section_description,
        procurementTypeOneTime: nt.procurement_type_one_time,
        procurementTypeRecurring: nt.procurement_type_recurring,
        followUpNone: nt.follow_up_none,
        followUpManualOffset: nt.follow_up_manual_offset,
        archiveToHistoryHelp: nt.archive_to_history_help,
        saveAndMoveToHistory: nt.save_and_move_to_history,
        moving: nt.moving,
        addInformation: nt.add_information,
        hideMove: nt.hide_move,
        moveToHistory: nt.move_to_history,
        historyNeedsSelection: nt.history_needs_selection,
        savingLabel: nt.saving,
        saveLabel: nt.save,
        cancelLabel: nt.cancel,
        searchLabel: nt.search,
        openLabel: nt.open,
        previousLabel: nt.previous,
        nextLabel: nt.next,
        statusLabel: nt.status,
        buyerUnknown: nt.buyer_unknown,
        moreLabel: nt.more,
        showLessLabel: nt.show_less,
        hitsSuffix: nt.hits_suffix,
        delete: nt.delete,
        deleteNoticeConfirm: nt.delete_notice_confirm,
        deleteHistoryConfirm: nt.delete_history_confirm,
        deletePermissionMessage: nt.delete_permission_message,
        unknownUser: nt.unknown_user,
    };

    const statusOptions = [
        { value: '', label: tf.notice_status_all },
        { value: 'ACTIVE', label: tf.notice_status_active },
        { value: 'EXPIRED', label: tf.notice_status_expired },
        { value: 'AWARDED', label: tf.notice_status_awarded },
        { value: 'CANCELLED', label: tf.notice_status_cancelled },
    ];

    const historyProcurementTypeOptions = [
        { value: 'one_time', label: noticesText.procurementTypeOneTime },
        { value: 'recurring', label: noticesText.procurementTypeRecurring },
    ];

    const historyFollowUpOptions = [
        { value: 'none', label: noticesText.followUpNone },
        { value: 'manual_offset', label: noticesText.followUpManualOffset },
    ];

    const bidStatusOptions = [
        { value: '', label: tf.bid_status_all },
        { value: 'discovered', label: tf.bid_status_discovered },
        { value: 'qualifying', label: tf.bid_status_qualifying },
        { value: 'go_no_go', label: tf.bid_status_go_no_go },
        { value: 'in_progress', label: tf.bid_status_in_progress },
        { value: 'submitted', label: tf.bid_status_submitted },
        { value: 'negotiation', label: tf.bid_status_negotiation },
        { value: 'won', label: tf.bid_status_won },
        { value: 'lost', label: tf.bid_status_lost },
        { value: 'no_go', label: tf.bid_status_no_go },
        { value: 'withdrawn', label: tf.bid_status_withdrawn },
        { value: 'archived', label: tf.bid_status_archived },
    ];

    const [selectedWatchListId, setSelectedWatchListId] = useState(() => filters.watch_list_id ?? '');
    const [searchQuery, setSearchQuery] = useState(filters.q ?? '');
    const [organizationName, setOrganizationName] = useState(filters.organization_name ?? '');
    const [selectedCpvItems, setSelectedCpvItems] = useState(cpvSelector?.selected ?? []);
    const [keywords, setKeywords] = useState(filters.keywords ?? '');
    // Distinguish watch list prefill keywords from user-entered keywords.
    const [keywordsSource, setKeywordsSource] = useState(() => (filters.watch_list_id ?? '') !== '' ? 'watch_list' : 'manual');
    const initialPublicationDateRange = publicationDateRangeFromFilters(filters);
    const [publicationDateFrom, setPublicationDateFrom] = useState(initialPublicationDateRange.from);
    const [publicationDateTo, setPublicationDateTo] = useState(initialPublicationDateRange.to);
    const [status, setStatus] = useState(filters.status ?? '');
    const [bidStatusFilter, setBidStatusFilter] = useState(filters.bid_status ?? '');
    const [historyTypeFilter, setHistoryTypeFilter] = useState(filters.history_type ?? '');
    const [expandedSavedNoticeIds, setExpandedSavedNoticeIds] = useState({});
    const [expandedNoticeSummaryIds, setExpandedNoticeSummaryIds] = useState({});
    const [editingSavedNoticeId, setEditingSavedNoticeId] = useState(null);
    const [editingHistoryNoticeId, setEditingHistoryNoticeId] = useState(null);
    const [archivingSavedNoticeId, setArchivingSavedNoticeId] = useState(null);
    const [isPrivateRequestFormOpen, setIsPrivateRequestFormOpen] = useState(false);
    const deadlineForm = useForm({
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
    const historyForm = useForm({
        selected_supplier_name: '',
        contract_value_mnok: '',
        procurement_type: '',
        follow_up_mode: '',
        follow_up_offset_months: '',
        contract_period_months: '',
    });
    const archiveHistoryForm = useForm({
        history_type: '',
    });
    const privateRequestForm = useForm({
        source_type: 'private_request',
        title: '',
        buyer_name: '',
        summary: '',
        deadline: '',
        reference_number: '',
        contact_person_name: '',
        contact_person_email: '',
        external_url: '',
        notes: '',
    });
    const isLiveMode = mode === 'live';
    const isSavedMode = mode === 'saved';
    const isHistoryMode = mode === 'history';
    const isAlertsTab = isLiveMode && tab === 'alerts';
    const isSavedOrHistoryMode = mode === 'saved' || mode === 'history';
    const liveSearchResultsRef = useRef(null);
    const shouldScrollToLiveSearchResultsRef = useRef(false);
    const worklistFilterOptions = isHistoryMode
        ? [{ value: '', label: noticesText.worklistFilterAllTypes }, ...historyTypeOptions]
        : bidStatusOptions;
    const worklistFilterValue = isHistoryMode ? historyTypeFilter : bidStatusFilter;
    const worklistFilterTitle = isHistoryMode ? noticesText.worklistFilterTitleHistory : noticesText.worklistFilterTitleSaved;
    const worklistFilterDescription = isHistoryMode
        ? noticesText.worklistFilterDescriptionHistory
        : noticesText.worklistFilterDescriptionSaved;
    const worklistFilterLabel = isHistoryMode ? noticesText.worklistFilterLabelHistory : noticesText.worklistFilterLabelSaved;
    const hasAppliedSearch = (filters.q ?? '').trim() !== '';
    const hasAppliedRefinements = [
        filters.organization_name,
        filters.cpv,
        filters.keywords,
        filters.watch_list_id,
        filters.publication_date_from,
        filters.publication_date_to,
        filters.publication_period,
        filters.status,
    ].some(
        (value) => (value ?? '').trim() !== '',
    );
    const liveSearchError = isLiveMode && typeof notices?.error === 'string' ? notices.error : '';
    const liveSearchFallbackUsed = isLiveMode && Boolean(notices?.meta?.fallback_used);
    const watchListOptions = savedSearches.map((item) => ({
        value: String(item.id),
        label: item.name,
        prefill: item.prefill ?? {},
    }));
    const activeWatchList = watchListOptions.find((item) => item.value === selectedWatchListId) ?? null;
    const isHistoryFormRecurring = historyForm.data.procurement_type === 'recurring';
    const shouldShowHistoryFollowUpField = historyForm.data.procurement_type !== '';
    const isHistoryFormManualOffset = historyForm.data.follow_up_mode === 'manual_offset';
    const historyNextFollowUpPreview = isHistoryFormManualOffset ? historyNextFollowUpPreviewDate(historyForm.data.follow_up_offset_months) : null;
    const totalHits = normalizeCount(notices?.meta?.numHitsTotal ?? notices?.meta?.total ?? 0);
    const accessibleHits = normalizeCount(notices?.meta?.numHitsAccessible ?? notices?.meta?.total ?? 0);
    const visibleHits = normalizeCount(notices?.data?.length ?? 0);
    const liveSearchCurrentPage = isLiveMode ? Number(notices?.meta?.current_page ?? 1) : 1;
    const emptyState = emptyStateContent(mode, hasAppliedSearch, hasAppliedRefinements, totalHits, visibleHits, liveSearchError, noticesText);
    const isCappedLiveSearch = isLiveMode && liveSearchError === '' && Boolean(notices?.meta?.is_capped) && totalHits > accessibleHits;
    const liveSearchHeading = liveSearchError !== ''
        ? noticesText.liveSearchErrorTitle
        : `${formatInteger(notices?.meta?.total ?? 0, locale)} ${noticesText.liveSearchHitsTitle}`;
    const showLiveSearchFallbackBanner = liveSearchFallbackUsed && liveSearchError === '';
    const watchAlertRows = Array.isArray(watchAlerts?.data) ? watchAlerts.data : [];
    const hasPrimarySearchInput = searchQuery.trim() !== '' || organizationName.trim() !== '';
    const useWatchListKeywords = selectedWatchListId !== '' && keywordsSource === 'watch_list' && !hasPrimarySearchInput;
    const submittedKeywords = useWatchListKeywords || keywordsSource === 'manual' ? keywords : '';

    useEffect(() => {
        setSelectedWatchListId(filters.watch_list_id ?? '');
        setSearchQuery(filters.q ?? '');
        setOrganizationName(filters.organization_name ?? '');
        setSelectedCpvItems(cpvSelector?.selected ?? []);
        setKeywords(filters.keywords ?? '');
        const nextPublicationDateRange = publicationDateRangeFromFilters(filters);
        setPublicationDateFrom(nextPublicationDateRange.from);
        setPublicationDateTo(nextPublicationDateRange.to);
        setStatus(filters.status ?? '');
        setBidStatusFilter(filters.bid_status ?? '');
        setHistoryTypeFilter(filters.history_type ?? '');
    }, [
        filters.bid_status,
        filters.history_type,
        cpvSelector?.selected,
        filters.keywords,
        filters.organization_name,
        filters.watch_list_id,
        filters.publication_period,
        filters.publication_date_from,
        filters.publication_date_to,
        filters.q,
        filters.relevance,
        filters.status,
    ]);

    useEffect(() => {
        if (!isSavedMode) {
            setEditingSavedNoticeId(null);
            deadlineForm.clearErrors();
        }
    }, [isSavedMode]);

    useEffect(() => {
        if (!isHistoryMode) {
            setEditingHistoryNoticeId(null);
            historyForm.clearErrors();
        }
    }, [isHistoryMode]);

    useEffect(() => {
        if (!isLiveMode || !shouldScrollToLiveSearchResultsRef.current || !liveSearchResultsRef.current) {
            return;
        }

        shouldScrollToLiveSearchResultsRef.current = false;

        liveSearchResultsRef.current.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    }, [isLiveMode, liveSearchCurrentPage]);

    const goToNoticePage = (url) => {
        if (!url) {
            return;
        }

        if (isLiveMode) {
            shouldScrollToLiveSearchResultsRef.current = true;

            router.visit(url, {
                preserveScroll: true,
                preserveState: true,
            });

            return;
        }

        router.visit(url);
    };

    const saveNotice = (notice) => {
        router.post(
            '/app/notices/save',
            {
                source_type: 'public_notice',
                notice_id: notice.notice_id,
                title: notice.title,
                buyer_name: notice.buyer_name,
                external_url: notice.external_url,
                summary: notice.summary,
                publication_date: notice.publication_date,
                deadline: notice.deadline,
                status: notice.status,
                cpv_code: notice.cpv_code,
            },
            {
                preserveScroll: true,
            },
        );
    };

    const submitPrivateRequest = () => {
        privateRequestForm.post('/app/notices/save', {
            preserveScroll: true,
            onSuccess: () => {
                privateRequestForm.reset();
                privateRequestForm.clearErrors();
                setIsPrivateRequestFormOpen(false);
            },
            onError: () => {
                setIsPrivateRequestFormOpen(true);
            },
        });
    };

    const removeNotice = (notice) => {
        if (!window.confirm(noticesText.deleteNoticeConfirm)) {
            return;
        }

        router.delete(`/app/notices/saved/${notice.saved_notice_id}`, {
            preserveScroll: true,
        });
    };

    const removeHistoryNotice = (notice) => {
        if (!window.confirm(noticesText.deleteHistoryConfirm)) {
            return;
        }

        router.delete(`/app/notices/history/${notice.saved_notice_id}`, {
            preserveScroll: true,
        });
    };

    const toggleSavedNoticeDetails = (noticeId) => {
        setExpandedSavedNoticeIds((current) => ({
            ...current,
            [noticeId]: !current[noticeId],
        }));
    };

    const openDeadlineEditor = (notice) => {
        setExpandedSavedNoticeIds((current) => ({
            ...current,
            [notice.id]: true,
        }));
        setEditingSavedNoticeId(notice.id);
        deadlineForm.clearErrors();
        deadlineForm.setData({
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
        setEditingSavedNoticeId(null);
        deadlineForm.reset();
        deadlineForm.clearErrors();
    };

    const updateSavedNoticeDeadlines = (notice) => {
        deadlineForm.patch(`/app/notices/saved/${notice.saved_notice_id}/deadlines`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setEditingSavedNoticeId(null);
                deadlineForm.reset();
                deadlineForm.clearErrors();
            },
        });
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

    const openHistoryEditor = (notice) => {
        setExpandedSavedNoticeIds((current) => ({
            ...current,
            [notice.id]: true,
        }));
        setEditingHistoryNoticeId(notice.id);
        historyForm.clearErrors();
        historyForm.setData(buildHistoryFormData(notice));
    };

    /**
     * Purpose:
     * Toggle the history metadata panel for one saved notice.
     *
     * Inputs:
     * The current history notice row payload.
     *
     * Returns:
     * void
     *
     * Side effects:
     * Expands or collapses the history metadata panel and resets the history form when closing.
     */
    const toggleHistoryEditor = (notice) => {
        const isExpanded = Boolean(expandedSavedNoticeIds[notice.id]);

        if (isExpanded) {
            setExpandedSavedNoticeIds((current) => ({
                ...current,
                [notice.id]: false,
            }));

            if (editingHistoryNoticeId === notice.id) {
                cancelHistoryEditor();
            }

            return;
        }

        openHistoryEditor(notice);
    };

    const cancelHistoryEditor = () => {
        setEditingHistoryNoticeId(null);
        historyForm.reset();
        historyForm.clearErrors();
    };

    const updateHistoryProcurementType = (procurementType) => {
        historyForm.clearErrors();

        if (procurementType === 'recurring' || procurementType === 'one_time') {
            historyForm.setData({
                ...historyForm.data,
                procurement_type: procurementType,
                follow_up_mode: historyForm.data.follow_up_mode === 'manual_offset' ? 'manual_offset' : 'none',
                contract_period_months: procurementType === 'recurring' ? historyForm.data.contract_period_months : '',
            });

            return;
        }

        historyForm.setData({
            ...historyForm.data,
            procurement_type: '',
            follow_up_mode: '',
            follow_up_offset_months: '',
            contract_period_months: '',
        });
    };

    const updateHistoryFollowUpMode = (followUpMode) => {
        historyForm.clearErrors();
        historyForm.setData({
            ...historyForm.data,
            follow_up_mode: followUpMode,
            follow_up_offset_months: followUpMode === 'manual_offset' ? historyForm.data.follow_up_offset_months : '',
        });
    };

    const updateHistoryMetadata = (notice) => {
        historyForm.patch(`/app/notices/saved/${notice.saved_notice_id}/history-metadata`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setEditingHistoryNoticeId(null);
                historyForm.reset();
                historyForm.clearErrors();
            },
        });
    };

    const handleLiveSearchHttpException = (response) => {
        if (response?.data?.component !== 'App/Notices/Index') {
            return true;
        }

        router.replace({
            url: response.data.url,
            component: response.data.component,
            props: response.data.props ?? {},
            flash: response.data.flash ?? {},
            preserveState: true,
            preserveScroll: true,
        });

        return false;
    };

    const applyFilters = () => {
        router.get(
            '/app/notices',
            buildNoticeQuery({
                mode: 'live',
                q: searchQuery,
                organization_name: organizationName,
                cpv: selectedCpvItems.map((item) => item.code).join(','),
                keywords: submittedKeywords,
                watch_list_id: selectedWatchListId,
                publication_date_from: publicationDateFrom,
                publication_date_to: publicationDateTo,
                status,
                keywords_mode: useWatchListKeywords ? 'any' : '',
                cockpit_scope: filters.cockpit_scope,
            }),
            {
                preserveState: true,
                replace: true,
                onHttpException: handleLiveSearchHttpException,
            },
        );
    };

    const clearFilters = () => {
        setSelectedWatchListId('');
        setSearchQuery('');
        setOrganizationName('');
        setSelectedCpvItems([]);
        setKeywords('');
        setKeywordsSource('manual');
        setPublicationDateFrom('');
        setPublicationDateTo('');
        setStatus('');
        setBidStatusFilter('');
        setHistoryTypeFilter('');

        router.get(
            '/app/notices',
            {
                mode: 'live',
                cockpit_scope: filters.cockpit_scope,
            },
            {
                preserveState: true,
                replace: true,
                onHttpException: handleLiveSearchHttpException,
            },
        );
    };

    const applySavedNoticeFilter = (nextFilter) => {
        if (isHistoryMode) {
            setHistoryTypeFilter(nextFilter);
        } else {
            setBidStatusFilter(nextFilter);
        }

        router.get(
            '/app/notices',
            buildNoticeQuery({
                mode,
                q: filters.q,
                organization_name: filters.organization_name,
                cpv: filters.cpv,
                keywords: filters.keywords,
                publication_date_from: filters.publication_date_from,
                publication_date_to: filters.publication_date_to,
                status: filters.status,
                bid_status: isHistoryMode ? '' : nextFilter,
                history_type: isHistoryMode ? nextFilter : '',
                cockpit_scope: filters.cockpit_scope,
            }),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const openArchiveSavedNoticeForm = (notice) => {
        setExpandedSavedNoticeIds((current) => ({
            ...current,
            [notice.id]: true,
        }));
        setArchivingSavedNoticeId(notice.id);
        archiveHistoryForm.clearErrors();
        archiveHistoryForm.setData('history_type', '');
    };

    const cancelArchiveSavedNoticeForm = () => {
        setArchivingSavedNoticeId(null);
        archiveHistoryForm.reset();
        archiveHistoryForm.clearErrors();
    };

    const submitArchiveSavedNotice = (notice, event = null) => {
        event?.preventDefault?.();

        if (!notice.actions?.archive_url || archiveHistoryForm.data.history_type.trim() === '') {
            return;
        }

        archiveHistoryForm.clearErrors();
        archiveHistoryForm.patch(notice.actions.archive_url, {
            preserveScroll: true,
            onSuccess: () => {
                cancelArchiveSavedNoticeForm();
            },
        });
    };

    const applyWatchListPrefill = (watchListId) => {
        const hadWatchListKeywords = keywordsSource === 'watch_list';

        setSelectedWatchListId(watchListId);

        if (watchListId === '') {
            setSelectedCpvItems([]);

            if (hadWatchListKeywords) {
                setKeywords('');
            }

            setKeywordsSource('manual');
            return;
        }

        const watchList = watchListOptions.find((item) => item.value === watchListId);
        const prefill = watchList?.prefill ?? null;

        if (!prefill) {
            return;
        }

        if (typeof prefill.organization_name === 'string') {
            setOrganizationName(prefill.organization_name);
        }

        if (Array.isArray(prefill.cpv_items)) {
            setSelectedCpvItems(prefill.cpv_items);
        }

        if (typeof prefill.keywords === 'string') {
            setKeywords(prefill.keywords);
            setKeywordsSource('watch_list');
        }

        if (typeof prefill.publication_date_from === 'string' || typeof prefill.publication_date_to === 'string') {
            setPublicationDateFrom(typeof prefill.publication_date_from === 'string' ? prefill.publication_date_from : '');
            setPublicationDateTo(typeof prefill.publication_date_to === 'string' ? prefill.publication_date_to : '');
        } else if (typeof prefill.publication_period === 'string') {
            const publicationDateRange = publicationDateRangeFromPeriod(prefill.publication_period);
            setPublicationDateFrom(publicationDateRange.from);
            setPublicationDateTo(publicationDateRange.to);
        }

        if (typeof prefill.status === 'string') {
            setStatus(prefill.status);
        }

    };

    const pageTitle = isLiveMode
        ? navigation.notices
        : (isHistoryMode ? navigation.history : navigation.worklist);
    const pageHeading = pageTitle;
    const pageSubtitle = isLiveMode
        ? tf.procurements_subtitle
        : (isHistoryMode
            ? noticesText.pageSubtitleHistory
            : noticesText.pageSubtitleSaved);

    if (isAlertsTab) {
        return (
            <CustomerAppLayout title={noticesText.alertsTitle} showPageTitle={false}>
                <div className="space-y-7">
                    <section className="space-y-1.5">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{noticesText.alertsTitle}</h1>
                    </section>

                    <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                        <div className="max-h-[calc(100vh-180px)] space-y-3.5 overflow-y-auto pr-1">
                            {watchAlertRows.length === 0 ? (
                                <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-base leading-6 text-slate-600">
                                    {noticesText.alertsEmpty}
                                </div>
                            ) : (
                                watchAlertRows.map((notice) => (
                                        <DiscoveryNoticeCard
                                            key={notice.id}
                                            notice={notice}
                                            locale={locale}
                                            canSaveToWorklist
                                            saveButtonLabel={translations.frontend.save_button}
                                            texts={noticesCardText}
                                            deleteAction={{
                                                href: notice.delete_url,
                                                label: noticesText.alertsDeleteLabel,
                                                confirmMessage: noticesText.alertsDeleteConfirm,
                                        }}
                                        actions={
                                            notice.external_url ? (
                                                <a
                                                    href={notice.external_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-flex min-w-[108px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-base font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                >
                                                    {noticesText.alertsOpenDoffin}
                                                </a>
                                            ) : null
                                        }
                                        provenanceBadges={notice.watch_profile_name ? [
                                            {
                                                key: `${notice.id}-watch-profile`,
                                                label: notice.watch_profile_name,
                                                className: 'bg-violet-50 text-violet-700 ring-violet-200',
                                            },
                                        ] : []}
                                    />
                                ))
                            )}
                        </div>
                    </section>
                </div>
            </CustomerAppLayout>
        );
    }


    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <div className="flex items-center gap-3">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{pageHeading}</h1>
                        <PageHelpButton
                            buttonLabel={nt.page_help_button ?? 'Hjelp'}
                            title={nt.page_help_title ?? 'Om Kunngjøringer'}
                            intro={nt.page_help_intro ?? 'Kunngjøringer er inngangen til nye anbudsmuligheter.'}
                            sections={[
                                {
                                    title: nt.page_help_section_usage ?? 'Slik bruker du siden',
                                    items: [
                                        {
                                            title: nt.page_help_item_live_title ?? 'Live-søk',
                                            text: nt.page_help_item_live_text ?? 'Søk direkte mot Doffin med filtre på frist, CPV, nøkkelord og relevans.',
                                        },
                                        {
                                            title: nt.page_help_item_saved_title ?? 'Arbeidsliste',
                                            text: nt.page_help_item_saved_text ?? 'Viser kunngjøringer du allerede har lagret og jobber aktivt med.',
                                        },
                                        {
                                            title: nt.page_help_item_save_title ?? 'Lagre en kunngjøring',
                                            text: nt.page_help_item_save_text ?? 'Lagrede kunngjøringer flyttes til arbeidslisten og følges opp som saker.',
                                        },
                                    ],
                                },
                            ]}
                        />
                    </div>
                    <p className="text-base leading-6 text-slate-600">{pageSubtitle}</p>
                </section>

                <div className={classNames(
                    'grid gap-5 xl:items-start',
                    'xl:grid-cols-1',
                )}>
                    <div className="space-y-5">
                        {isLiveMode ? (
                            <>
                                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-5">
                                <div className="mb-3">
                                <div className="text-base font-medium text-slate-900">{noticesText.liveTitle}</div>
                                <p className="mt-1 text-base leading-6 text-slate-600">
                                    {noticesText.liveDescription}
                                </p>
                            </div>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    applyFilters();
                                }}
                                className="flex"
                            >
                                <label className="relative flex-1 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                    <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                                    <input
                                        type="search"
                                        value={searchQuery}
                                        onChange={(event) => setSearchQuery(event.target.value)}
                                        placeholder={noticesText.liveSearchPlaceholder}
                                        className="h-[54px] w-full border-0 bg-transparent pl-12 pr-4 text-base text-slate-900 outline-none placeholder:text-slate-500 focus:ring-0"
                                    />
                                </label>
                            </form>
                        </section>

                        <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="mb-4">
                                    <div className="flex items-center gap-2.5">
                                        <FilterIcon className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-xl font-semibold text-slate-950">{tf.filters_title}</h2>
                                    </div>
                                    <p className="mt-1 text-base leading-6 text-slate-600">
                                    {noticesText.filtersDescription}
                                    </p>
                                </div>

                            <div className="space-y-3.5">
                                <label className="space-y-2">
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-base font-medium text-slate-700">{navigation.watch_lists}</span>
                                        {activeWatchList ? (
                                            <span className="inline-flex items-center rounded-full bg-violet-50 px-3 py-1.5 text-base font-medium leading-6 text-violet-700 ring-1 ring-inset ring-violet-200">
                                                {common.active}
                                            </span>
                                        ) : null}
                                    </div>
                                    <select
                                        value={selectedWatchListId}
                                        disabled={watchListOptions.length === 0}
                                        onChange={(event) => applyWatchListPrefill(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400"
                                    >
                                        <option value="">{watchListOptions.length === 0 ? noticesText.watchListPlaceholderEmpty : noticesText.watchListPlaceholder}</option>
                                        {watchListOptions.map((item) => (
                                            <option key={item.value} value={item.value}>
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                    {activeWatchList ? (
                                        <div className="rounded-xl border border-violet-200 bg-violet-50/70 px-3 py-2.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-base font-semibold uppercase tracking-[0.12em] text-violet-700">{noticesText.watchListActiveTitle}</span>
                                                <span className="text-base font-medium text-violet-900">{activeWatchList.label}</span>
                                            </div>
                                            <p className="mt-1 text-base leading-6 text-violet-800">
                                                {noticesText.watchListActiveDescription}
                                            </p>
                                        </div>
                                    ) : (
                                        <p className="text-base leading-6 text-slate-600">
                                            {watchListOptions.length === 0
                                                ? noticesText.watchListEmptyHelp
                                                : noticesText.watchListHelp}
                                        </p>
                                    )}
                                </label>

                                <div className="grid gap-3.5 md:grid-cols-2 xl:grid-cols-3">
                                    <label className="space-y-2">
                                        <span className="text-base font-medium text-slate-700">{tf.organization_name}</span>
                                        <input
                                            type="text"
                                            value={organizationName}
                                            onChange={(event) => setOrganizationName(event.target.value)}
                                            placeholder={noticesText.organizationPlaceholder}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        />
                                    </label>
                                    <CpvSelector
                                        endpoint={cpvSelector?.endpoint ?? '/app/notices/cpv-suggestions'}
                                        selectedItems={selectedCpvItems}
                                        onSelectedItemsChange={setSelectedCpvItems}
                                        popularItems={cpvSelector?.popular ?? []}
                                        labelHint={nt.hint_cpv}
                                    />
                                    <label className="space-y-2">
                                        <span className="text-base font-medium text-slate-700">{tf.keyword}</span>
                                        <input
                                            type="text"
                                            value={keywords}
                                            onChange={(event) => {
                                                setKeywords(event.target.value);
                                                setKeywordsSource('manual');
                                            }}
                                            placeholder={noticesText.keywordsPlaceholder}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        />
                                        <p className="text-base leading-6 text-slate-600">{noticesText.keywordsHelp}</p>
                                    </label>
                                    <label className="space-y-2">
                                        <span className="text-base font-medium text-slate-700">{noticesText.publicationDateLabel}</span>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            <label className="space-y-1">
                                                <span className="text-base font-medium uppercase tracking-[0.1em] text-slate-600">{noticesText.fromDateLabel}</span>
                                                <input
                                                    type="date"
                                                    value={publicationDateFrom}
                                                    onChange={(event) => setPublicationDateFrom(event.target.value)}
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                />
                                            </label>
                                            <label className="space-y-1">
                                                <span className="text-base font-medium uppercase tracking-[0.1em] text-slate-600">{noticesText.toDateLabel}</span>
                                                <input
                                                    type="date"
                                                    value={publicationDateTo}
                                                    onChange={(event) => setPublicationDateTo(event.target.value)}
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                />
                                            </label>
                                        </div>
                                    </label>
                                    <label className="space-y-2">
                                        <span className="text-base font-medium text-slate-700">{common.status}</span>
                                        <select
                                            value={status}
                                            onChange={(event) => setStatus(event.target.value)}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        >
                                            {statusOptions.map((option) => (
                                                <option key={option.value || 'empty'} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <div className="mt-5 flex flex-wrap gap-2.5">
                                <button
                                    type="button"
                                    onClick={applyFilters}
                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-5 py-2.5 text-base font-semibold text-white transition hover:bg-violet-700"
                                >
                                    {common.search}
                                </button>
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {tf.clear_filters}
                                </button>
                            </div>
                        </section>

                            </>
                        ) : null}

                        {supportMode?.active && supportMode?.message ? (
                            <section className="rounded-[20px] border border-amber-200 bg-amber-50 px-5 py-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="text-base font-medium text-amber-900">{translations.frontend.support_mode_label}</div>
                                <p className="mt-1 text-base leading-6 text-amber-800">{supportMode.message}</p>
                            </section>
                        ) : null}

                        {isSavedMode ? (
                            <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="space-y-4">
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <div className="text-base font-medium text-slate-900">{noticesText.privateRequestTitle}</div>
                                            <p className="mt-1 text-base leading-6 text-slate-600">
                                                {noticesText.privateRequestDescription}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            aria-expanded={isPrivateRequestFormOpen}
                                            aria-controls="private-request-form"
                                            onClick={() => setIsPrivateRequestFormOpen((current) => !current)}
                                            className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                        >
                                            {isPrivateRequestFormOpen ? noticesText.privateRequestToggleHide : noticesText.privateRequestToggleShow}
                                        </button>
                                    </div>

                                    {isPrivateRequestFormOpen ? (
                                        <form
                                            id="private-request-form"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                submitPrivateRequest();
                                            }}
                                            className="space-y-4"
                                        >
                                            <input type="hidden" name="source_type" value={privateRequestForm.data.source_type} />

                                            <div className="grid gap-3.5 md:grid-cols-2">
                                                <label className="space-y-2 md:col-span-2">
                                                    <span className="text-base font-medium text-slate-700">{noticesText.privateRequestFieldTitle}</span>
                                                    <input
                                                        type="text"
                                                        value={privateRequestForm.data.title}
                                                        onChange={(event) => privateRequestForm.setData('title', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder={noticesText.privateRequestPlaceholderTitle}
                                                    />
                                                    {privateRequestForm.errors.title ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.title}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-base font-medium text-slate-700">{noticesText.buyerLabel}</span>
                                                    <input
                                                        type="text"
                                                        value={privateRequestForm.data.buyer_name}
                                                        onChange={(event) => privateRequestForm.setData('buyer_name', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder={noticesText.privateRequestPlaceholderBuyerName}
                                                    />
                                                    {privateRequestForm.errors.buyer_name ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.buyer_name}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-base font-medium text-slate-700">{translations.common.deadline}</span>
                                                    <input
                                                        type="date"
                                                        value={privateRequestForm.data.deadline}
                                                        onChange={(event) => privateRequestForm.setData('deadline', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                    />
                                                    {privateRequestForm.errors.deadline ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.deadline}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2 md:col-span-2">
                                                    <span className="text-base font-medium text-slate-700">{noticesText.privateRequestFieldSummary}</span>
                                                    <textarea
                                                        value={privateRequestForm.data.summary}
                                                        onChange={(event) => privateRequestForm.setData('summary', event.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-base text-slate-900 outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder={noticesText.privateRequestPlaceholderSummary}
                                                    />
                                                    {privateRequestForm.errors.summary ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.summary}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-base font-medium text-slate-700">{noticesText.privateRequestFieldReference}</span>
                                                    <input
                                                        type="text"
                                                        value={privateRequestForm.data.reference_number}
                                                        onChange={(event) => privateRequestForm.setData('reference_number', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder={noticesText.privateRequestPlaceholderReference}
                                                    />
                                                    {privateRequestForm.errors.reference_number ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.reference_number}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-base font-medium text-slate-700">{noticesText.privateRequestFieldContactPerson}</span>
                                                    <input
                                                        type="text"
                                                        value={privateRequestForm.data.contact_person_name}
                                                        onChange={(event) => privateRequestForm.setData('contact_person_name', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder={noticesText.privateRequestPlaceholderContactPerson}
                                                    />
                                                    {privateRequestForm.errors.contact_person_name ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.contact_person_name}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-base font-medium text-slate-700">{noticesText.privateRequestFieldContactEmail}</span>
                                                    <input
                                                        type="email"
                                                        value={privateRequestForm.data.contact_person_email}
                                                        onChange={(event) => privateRequestForm.setData('contact_person_email', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder={noticesText.privateRequestPlaceholderContactEmail}
                                                    />
                                                    {privateRequestForm.errors.contact_person_email ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.contact_person_email}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2 md:col-span-2">
                                                    <span className="text-base font-medium text-slate-700">{noticesText.privateRequestFieldExternalUrl}</span>
                                                    <input
                                                        type="url"
                                                        value={privateRequestForm.data.external_url}
                                                        onChange={(event) => privateRequestForm.setData('external_url', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder={noticesText.privateRequestPlaceholderExternalUrl}
                                                    />
                                                    {privateRequestForm.errors.external_url ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.external_url}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2 md:col-span-2">
                                                    <span className="text-base font-medium text-slate-700">{noticesText.privateRequestFieldNotes}</span>
                                                    <textarea
                                                        value={privateRequestForm.data.notes}
                                                        onChange={(event) => privateRequestForm.setData('notes', event.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-base text-slate-900 outline-none transition placeholder:text-slate-500 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder={noticesText.privateRequestPlaceholderNotes}
                                                    />
                                                    {privateRequestForm.errors.notes ? (
                                                        <p className="text-base text-rose-600">{privateRequestForm.errors.notes}</p>
                                                    ) : null}
                                                </label>
                                            </div>

                                            <div className="flex flex-wrap gap-2.5">
                                                <button
                                                    type="submit"
                                                    disabled={privateRequestForm.processing}
                                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-5 py-2.5 text-base font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {privateRequestForm.processing ? noticesText.privateRequestSaving : noticesText.privateRequestSubmit}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        privateRequestForm.reset();
                                                        privateRequestForm.clearErrors();
                                                    }}
                                                    disabled={privateRequestForm.processing}
                                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {noticesText.privateRequestReset}
                                                </button>
                                            </div>
                                        </form>
                                    ) : (
                                        <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-base leading-6 text-slate-600">
                                            {noticesText.privateRequestFormHidden}
                                        </div>
                                    )}
                                </div>
                            </section>
                        ) : null}

                        {isSavedOrHistoryMode ? (
                            <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                    <div>
                                        <div className="text-base font-medium text-slate-900">{worklistFilterTitle}</div>
                                        <p className="mt-1 text-base leading-6 text-slate-600">
                                            {worklistFilterDescription}
                                        </p>
                                    </div>

                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                        <label className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                                            <span className="whitespace-nowrap text-base font-medium text-slate-700">{worklistFilterLabel}</span>
                                            <select
                                                value={worklistFilterValue}
                                                onChange={(event) => applySavedNoticeFilter(event.target.value)}
                                                className="h-11 min-w-[240px] rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                            >
                                                {worklistFilterOptions.map((option) => (
                                                    <option key={option.value || 'empty'} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        {worklistFilterValue ? (
                                            <button
                                                type="button"
                                                onClick={() => applySavedNoticeFilter('')}
                                                className="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                            >
                                                {noticesText.worklistClearFilter}
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                            </section>
                        ) : null}

                        <section ref={liveSearchResultsRef} id="doffin-results" className="scroll-mt-28 space-y-3.5">
                            <div className="space-y-1">
                                <div className="text-base font-medium uppercase tracking-[0.16em] text-slate-600">
                                    {source?.label}
                                </div>
                                <div className="text-[17px] font-semibold text-slate-950">{liveSearchHeading}</div>
                            </div>

                            {isCappedLiveSearch ? (
                                <AlertBox>
                                    {noticesText.liveCappedWarning?.replace(':total', formatInteger(totalHits, locale)).replace(':accessible', formatInteger(accessibleHits, locale))}
                                </AlertBox>
                            ) : null}

                            {showLiveSearchFallbackBanner ? (
                                <AlertBox>
                                    {noticesText.liveFallbackBanner}
                                </AlertBox>
                            ) : null}

                            {notices.data.length === 0 ? (
                                <div className="rounded-[22px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                    <div className="text-lg font-semibold text-slate-900">{emptyState.title}</div>
                                    <p className="mt-2 text-base leading-6 text-slate-600">{emptyState.body}</p>
                                </div>
                            ) : (
                                <div className="space-y-3.5">
                                    {notices.data.map((notice) => {
                                        const isPrivateRequest = notice.source_type === 'private_request';
                                        const statusTag = statusBadge(notice.status, notice.deadline, noticesText);
                                        const deadlineBadge = isSavedOrHistoryMode
                                            ? savedNoticeDeadlineBadge(notice, locale, noticesText)
                                            : {
                                                label: `${noticesText.deadlinePrefix} ${formatDate(notice.deadline, locale)}`,
                                                className: 'bg-slate-100 text-slate-700 ring-slate-200',
                                            };
                                        const isEditingDeadlines = isSavedMode && editingSavedNoticeId === notice.id;
                                        const timelineSteps = isSavedOrHistoryMode && !isPrivateRequest
                                            ? savedNoticeTimelineSteps(
                                                isEditingDeadlines
                                                    ? {
                                                        questions_rfi_deadline_at: deadlineForm.data.questions_rfi_deadline_at || null,
                                                        rfi_submission_deadline_at: deadlineForm.data.rfi_submission_deadline_at || null,
                                                        questions_rfp_deadline_at: deadlineForm.data.questions_rfp_deadline_at || null,
                                                        award_date_at: deadlineForm.data.award_date_at || null,
                                                        deadline: notice.deadline,
                                                    }
                                                    : notice,
                                                noticesText,
                                            )
                                            : [];
                                        const businessReviews = (notice.business_reviews ?? []).filter((review) => review.business_review_at);
                                        const isDetailsExpanded = Boolean(expandedSavedNoticeIds[notice.id]);
                                        const isEditingHistory = isHistoryMode && editingHistoryNoticeId === notice.id;
                                        const historyContractLabel = isHistoryMode ? historyContractSummary(notice, noticesText) : null;
                                        const needsHistorySelection = isHistoryMode ? historyNeedsStructuredSelection(notice) : false;
                                        const noticeSummary = (notice.summary ?? '').trim();
                                        const isNoticeSummaryExpandable = noticeSummary.length > noticeSummaryPreviewLimit;
                                        const isNoticeSummaryExpanded = Boolean(expandedNoticeSummaryIds[notice.id]);
                                        const noticeSummaryStyle = isNoticeSummaryExpandable && !isNoticeSummaryExpanded
                                            ? noticeSummaryCollapsedStyle
                                            : undefined;

                                        if (isLiveMode) {
                                            return (
                                                <DiscoveryNoticeCard
                                                    key={notice.id}
                                                    notice={notice}
                                                    locale={locale}
                                                    canSaveToWorklist
                                                    saveButtonLabel={translations.frontend.save_button}
                                                    texts={noticesCardText}
                                                    actions={
                                                        notice.external_url ? (
                                                            <a
                                                                href={notice.external_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex min-w-[108px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-base font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                {noticesText.openInDoffinLabel}
                                                            </a>
                                                        ) : null
                                                    }
                                                />
                                            );
                                        }

                                        return (
                                            <article
                                                key={notice.id}
                                                className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)] transition hover:border-slate-300"
                                            >
                                                <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2.5">
                                                            <h2 className="text-[1.7rem] font-semibold tracking-tight text-slate-950">
                                                                {notice.title}
                                                            </h2>
                                                        </div>

                                                        <div className="mt-1.5 flex flex-wrap items-center gap-4 text-base text-slate-700">
                                                            <span className="inline-flex items-center gap-2">
                                                                <BuildingIcon className="h-4 w-4 text-slate-400" />
                                                                {notice.buyer_name || noticesText.buyerUnknown}
                                                            </span>
                                                            {isSavedOrHistoryMode && notice.bid_status ? (
                                                                <span className={classNames('inline-flex items-center rounded-full px-3 py-1.5 text-base font-semibold leading-6 ring-1 ring-inset', bidStatusBadgeClassName(notice.bid_status))}>
                                                                    {notice.bid_status_label ?? notice.bid_status}
                                                                </span>
                                                            ) : null}
                                                        </div>

                                                        {isHistoryMode && notice.history_type_label ? (
                                                            <div className="mt-2">
                                                                <span className="inline-flex items-center rounded-full bg-violet-50 px-3 py-1.5 text-base font-semibold leading-6 text-violet-700 ring-1 ring-inset ring-violet-200">
                                                                {noticesText.typePrefix}: {notice.history_type_label}
                                                                </span>
                                                            </div>
                                                        ) : null}

                                                        <div className="mt-3 max-w-4xl text-base leading-7 text-slate-700 whitespace-pre-line">
                                                            <div style={noticeSummaryStyle}>{summarizeText(notice.summary, noticesText)}</div>
                                                            {isNoticeSummaryExpandable ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setExpandedNoticeSummaryIds((current) => ({
                                                                        ...current,
                                                                        [notice.id]: !current[notice.id],
                                                                    }))}
                                                                    className="mt-2 text-base font-medium text-violet-700 transition hover:text-violet-800"
                                                                >
                                                                    {isNoticeSummaryExpanded ? noticesText.showLessLabel : noticesText.moreLabel}
                                                                </button>
                                                            ) : null}
                                                        </div>

                                                        {isSavedOrHistoryMode ? (
                                                            isPrivateRequest ? (
                                                                <div className="mt-4 rounded-2xl border border-violet-200 bg-violet-50/70 px-4 py-4">
                                                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                                                        <div>
                                                                            <div className="text-base font-semibold uppercase tracking-[0.14em] text-violet-700">
                                                                                {noticesText.privateRequestSourceLabel}
                                                                            </div>
                                                                            <p className="mt-1 text-base leading-6 text-violet-950/75">
                                                                                {noticesText.privateRequestSectionDescription}
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                    {isEditingDeadlines ? (
                                                                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                                                            <div className="rounded-xl bg-white px-3 py-2.5 shadow-[0_1px_0_rgba(15,23,42,0.03)]">
                                                                                <div className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600">Registrert</div>
                                                                                <div className="mt-1 text-base font-medium leading-6 text-slate-900">
                                                                                    {notice.saved_at ? formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' }) : noticesText.notRegistered}
                                                                                </div>
                                                                            </div>
                                                                            <div className="rounded-xl bg-white px-3 py-2.5 shadow-[0_1px_0_rgba(15,23,42,0.03)]">
                                                                                <div className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600">Oppdragsgiver</div>
                                                                                <div className="mt-1 text-base font-medium leading-6 text-slate-900">
                                                                                    {notice.buyer_name || noticesText.notRegistered}
                                                                                </div>
                                                                            </div>
                                                                            <div className="rounded-xl bg-white px-3 py-2.5 shadow-[0_1px_0_rgba(15,23,42,0.03)]">
                                                                                <div className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600">Frist</div>
                                                                                <div className="mt-1 text-base font-medium leading-6 text-slate-900">
                                                                                    {notice.deadline ? formatDate(notice.deadline, locale) : noticesText.notRegistered}
                                                                                </div>
                                                                            </div>
                                                                            <div className="space-y-1.5">
                                                                                <label className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600" htmlFor={`reference_number_${notice.id}`}>
                                                                                    Referanse
                                                                                </label>
                                                                                <input
                                                                                    id={`reference_number_${notice.id}`}
                                                                                    type="text"
                                                                                    value={deadlineForm.data.reference_number}
                                                                                    onChange={(event) => deadlineForm.setData('reference_number', event.target.value)}
                                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-base text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                                />
                                                                                {deadlineForm.errors.reference_number ? (
                                                                                    <p className="text-base text-rose-600">{deadlineForm.errors.reference_number}</p>
                                                                                ) : null}
                                                                            </div>
                                                                            <div className="space-y-1.5">
                                                                                <label className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600" htmlFor={`contact_person_name_${notice.id}`}>
                                                                                    Kontaktperson
                                                                                </label>
                                                                                <input
                                                                                    id={`contact_person_name_${notice.id}`}
                                                                                    type="text"
                                                                                    value={deadlineForm.data.contact_person_name}
                                                                                    onChange={(event) => deadlineForm.setData('contact_person_name', event.target.value)}
                                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-base text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                                />
                                                                                {deadlineForm.errors.contact_person_name ? (
                                                                                    <p className="text-base text-rose-600">{deadlineForm.errors.contact_person_name}</p>
                                                                                ) : null}
                                                                            </div>
                                                                            <div className="space-y-1.5">
                                                                                <label className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600" htmlFor={`contact_person_email_${notice.id}`}>
                                                                                    Kontakt e-post
                                                                                </label>
                                                                                <input
                                                                                    id={`contact_person_email_${notice.id}`}
                                                                                    type="email"
                                                                                    value={deadlineForm.data.contact_person_email}
                                                                                    onChange={(event) => deadlineForm.setData('contact_person_email', event.target.value)}
                                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-base text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                                />
                                                                                {deadlineForm.errors.contact_person_email ? (
                                                                                    <p className="text-base text-rose-600">{deadlineForm.errors.contact_person_email}</p>
                                                                                ) : null}
                                                                            </div>
                                                                            <div className="sm:col-span-2">
                                                                                <div className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600">{noticesText.externalLinkLabel}</div>
                                                                                <div className="mt-1 text-base font-medium leading-6 text-slate-900">
                                                                                    {notice.external_url ? (
                                                                                        <a
                                                                                            href={notice.external_url}
                                                                                            target="_blank"
                                                                                            rel="noreferrer"
                                                                                            className="font-medium text-violet-700 transition hover:text-violet-800"
                                                                                        >
                                                                                            {noticeExternalLinkLabel(notice, noticesText)}
                                                                                        </a>
                                                                                    ) : (
                                                                                        noticesText.notRegistered
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                            <div className="sm:col-span-2 space-y-1.5">
                                                                                <label className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600" htmlFor={`notes_${notice.id}`}>
                                                                                    Notater
                                                                                </label>
                                                                                <textarea
                                                                                    id={`notes_${notice.id}`}
                                                                                    value={deadlineForm.data.notes}
                                                                                    onChange={(event) => deadlineForm.setData('notes', event.target.value)}
                                                                                    rows={4}
                                                                                    className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-base text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                                    placeholder="Valgfritt notat"
                                                                                />
                                                                                {deadlineForm.errors.notes ? (
                                                                                    <p className="text-base text-rose-600">{deadlineForm.errors.notes}</p>
                                                                                ) : null}
                                                                            </div>
                                                                        </div>
                                                                    ) : (
                                                                    <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                                                                            {privateRequestSummaryFields(notice, locale, noticesText).map((field) => (
                                                                                <div
                                                                                    key={field.key}
                                                                                    className={classNames(
                                                                                        'rounded-xl bg-white px-3 py-2.5 shadow-[0_1px_0_rgba(15,23,42,0.03)]',
                                                                                        field.span ? 'sm:col-span-2' : '',
                                                                                    )}
                                                                                >
                                                                                    <dt className="text-base font-semibold uppercase tracking-[0.1em] text-slate-600">
                                                                                        {field.label}
                                                                                    </dt>
                                                                                    <dd className="mt-1 text-base font-medium leading-6 text-slate-900">
                                                                                        {field.value}
                                                                                    </dd>
                                                                                </div>
                                                                            ))}
                                                                        </dl>
                                                                    )}
                                                                </div>
                                                            ) : timelineSteps.length > 0 ? (
                                                                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                                                                    <div className="grid grid-cols-5 gap-2">
                                                                        {timelineSteps.map((step, index) => {
                                                                            const isActive = Boolean(step.date);

                                                                            return (
                                                                                <div key={step.key} className="relative text-center">
                                                                                    {index > 0 ? (
                                                                                        <span className="absolute right-1/2 top-[30px] h-px w-full bg-slate-200" aria-hidden="true" />
                                                                                    ) : null}
                                                                                    {index < timelineSteps.length - 1 ? (
                                                                                        <span className="absolute left-1/2 top-[30px] h-px w-full bg-slate-200" aria-hidden="true" />
                                                                                    ) : null}
                                                                                    <div className="text-base font-medium uppercase tracking-[0.1em] text-slate-600">
                                                                                        {step.label}
                                                                                    </div>
                                                                                    <div className="relative mt-2 flex justify-center">
                                                                                        <span
                                                                                            className={classNames(
                                                                                                'relative z-10 h-3 w-3 rounded-full ring-4 ring-slate-50',
                                                                                                isActive ? 'bg-violet-600' : 'bg-slate-300',
                                                                                            )}
                                                                                        />
                                                                                    </div>
                                                                                    <div className={classNames('mt-2 text-base', isActive ? 'text-slate-700' : 'text-slate-600')}>
                                                                                        {isActive ? formatDeadlineDate(step.date) : '—'}
                                                                                    </div>
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                    {businessReviews.length > 0 ? (
                                                                        <div className="mt-3 rounded-2xl border border-blue-200 bg-blue-50/70 px-4 py-4">
                                                                            <div className="text-base font-semibold uppercase tracking-[0.1em] text-blue-700">
                                                                                    {noticesText.businessReviewTitle}
                                                                            </div>
                                                                            <div className="mt-3 space-y-3">
                                                                                {businessReviews.map((review) => (
                                                                                    <div key={review.id} className="flex items-center gap-3 rounded-xl bg-white px-3 py-2.5">
                                                                                        <span
                                                                                            className="h-3 w-3 rounded-full bg-blue-700 ring-4 ring-blue-100"
                                                                                            aria-hidden="true"
                                                                                        />
                                                                                        <div className="min-w-0">
                                                                                            <div className="text-base font-medium text-slate-900">
                                                                                                {formatDate(review.business_review_at, locale)}
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        </div>
                                                                    ) : null}
                                                                </div>
                                                            ) : null
                                                        ) : null}

                                                    </div>

                                                    <div className="flex shrink-0 flex-row gap-3 lg:flex-col">
                                                        {isSavedOrHistoryMode && notice.show_url ? (
                                                            <Link
                                                                href={notice.show_url}
                                                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-base font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                Åpne sak
                                                            </Link>
                                                        ) : null}
                                                        {isHistoryMode ? (
                                                            <button
                                                                type="button"
                                                                aria-expanded={isDetailsExpanded}
                                                                onClick={() => toggleHistoryEditor(notice)}
                                                                className={classNames(
                                                                    'inline-flex min-w-[132px] items-center justify-center rounded-xl border px-4 py-2.5 text-base font-semibold transition',
                                                                    isDetailsExpanded
                                                                        ? 'border-slate-300 bg-slate-100 text-slate-900'
                                                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                                                )}
                                                            >
                                                                {noticesText.addInformation}
                                                            </button>
                                                        ) : null}
                                                        {isSavedMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => openDeadlineEditor(notice)}
                                                                className={classNames(
                                                                    'inline-flex min-w-[132px] items-center justify-center rounded-xl border px-4 py-2.5 text-base font-semibold transition',
                                                                    isEditingDeadlines
                                                                        ? 'border-slate-300 bg-slate-100 text-slate-900'
                                                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                                                )}
                                                                >
                                                                Rediger frister
                                                            </button>
                                                        ) : null}
                                                        {isLiveMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => saveNotice(notice)}
                                                                disabled={notice.is_saved}
                                                                className={classNames(
                                                                    'inline-flex min-w-[132px] items-center justify-center rounded-xl border px-4 py-2.5 text-base font-semibold transition',
                                                                    notice.is_saved
                                                                        ? 'cursor-not-allowed border-emerald-200 bg-emerald-50 text-emerald-700'
                                                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                                                )}
                                                            >
                                                                {notice.is_saved ? noticesText.savedLabel : common.save}
                                                            </button>
                                                        ) : null}
                                                        {isSavedMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => (
                                                                    archivingSavedNoticeId === notice.id
                                                                        ? cancelArchiveSavedNoticeForm()
                                                                        : openArchiveSavedNoticeForm(notice)
                                                                )}
                                                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                {archivingSavedNoticeId === notice.id ? noticesText.hideMove : noticesText.moveToHistory}
                                                            </button>
                                                        ) : null}
                                                        {isSavedMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    if (!notice.can_delete) {
                                                                        window.alert(noticesText.deletePermissionMessage.replace(':name', notice.saved_by_name ?? noticesText.unknownUser));
                                                                        return;
                                                                    }
                                                                    removeNotice(notice);
                                                                }}
                                                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-base font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                            >
                                                                {noticesText.delete}
                                                            </button>
                                                        ) : null}
                                                        {isHistoryMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    if (!notice.can_delete) {
                                                                        window.alert(noticesText.deletePermissionMessage.replace(':name', notice.saved_by_name ?? noticesText.unknownUser));
                                                                        return;
                                                                    }
                                                                    removeHistoryNotice(notice);
                                                                }}
                                                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-base font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                            >
                                                                {noticesText.delete}
                                                            </button>
                                                        ) : null}
                                                        {notice.external_url ? (
                                                            <a
                                                                href={notice.external_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex min-w-[108px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-base font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                {noticeExternalLinkLabel(notice, noticesText)}
                                                            </a>
                                                        ) : null}
                                                    </div>
                                                </div>

                                                {isSavedMode && archivingSavedNoticeId === notice.id ? (
                                                    <form
                                                        onSubmit={(event) => submitArchiveSavedNotice(notice, event)}
                                                        className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"
                                                    >
                                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                            <div>
                                                            <div className="text-base font-semibold uppercase tracking-[0.16em] text-slate-600">
                                                                    {noticesText.moveToHistory}
                                                                </div>
                                                                <p className="mt-1 text-base leading-6 text-slate-600">
                                                                    {noticesText.archiveToHistoryHelp}
                                                                </p>
                                                            </div>

                                                            <button
                                                                type="button"
                                                                onClick={cancelArchiveSavedNoticeForm}
                                                                className="inline-flex min-h-9 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                {noticesText.cancelLabel}
                                                            </button>
                                                        </div>

                                                        <label className="mt-4 block space-y-2">
                                                            <span className="text-base font-medium text-slate-700">Type</span>
                                                            <select
                                                                value={archiveHistoryForm.data.history_type}
                                                                onChange={(event) => archiveHistoryForm.setData('history_type', event.target.value)}
                                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base text-slate-900 outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                            >
                                                                <option value="">Velg type</option>
                                                                {historyTypeOptions.map((option) => (
                                                                    <option key={option.value} value={option.value}>
                                                                        {option.label}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            {archiveHistoryForm.errors.history_type ? (
                                                                <p className="text-base text-rose-600">{archiveHistoryForm.errors.history_type}</p>
                                                            ) : null}
                                                        </label>

                                                        <div className="mt-4 flex flex-wrap gap-3">
                                                            <button
                                                                type="button"
                                                                onClick={(event) => submitArchiveSavedNotice(notice, event)}
                                                                disabled={archiveHistoryForm.processing || archiveHistoryForm.data.history_type.trim() === ''}
                                                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-base font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {archiveHistoryForm.processing ? noticesText.moving : noticesText.saveAndMoveToHistory}
                                                            </button>
                                                        </div>
                                                    </form>
                                                ) : null}

                                                        {(isSavedMode || (isHistoryMode && isDetailsExpanded)) ? (
                                                            <div className="mt-4 border-t border-slate-100 pt-4 text-base leading-6 text-slate-600">
                                                                {isPrivateRequest ? (
                                                                    <div className="grid gap-2 sm:grid-cols-2">
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">{noticesText.savedByLabel}:</span>{' '}
                                                                            <span>{notice.saved_by_name || noticesText.notRegistered}</span>
                                                                        </div>
                                                                        {notice.saved_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.registeredLabel}:</span>{' '}
                                                                                <span>{formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' })}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">{noticesText.buyerLabel}:</span>{' '}
                                                                            <span>{notice.buyer_name || noticesText.notRegistered}</span>
                                                                        </div>
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">{noticesText.deadlineLabel}:</span>{' '}
                                                                            <span>{notice.deadline ? formatDate(notice.deadline, locale) : noticesText.notRegistered}</span>
                                                                        </div>
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">{noticesText.referenceLabel}:</span>{' '}
                                                                            <span>{notice.reference_number || noticesText.notRegistered}</span>
                                                                        </div>
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">{noticesText.contactPersonLabel}:</span>{' '}
                                                                            <span>{notice.contact_person_name || noticesText.notRegistered}</span>
                                                                        </div>
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">{noticesText.contactEmailLabel}:</span>{' '}
                                                                            <span>{notice.contact_person_email || noticesText.notRegistered}</span>
                                                                        </div>
                                                                        <div className="sm:col-span-2">
                                                                            <span className="font-medium text-slate-700">{noticesText.externalLinkLabel}:</span>{' '}
                                                                            {notice.external_url ? (
                                                                                <a
                                                                                    href={notice.external_url}
                                                                                    target="_blank"
                                                                                    rel="noreferrer"
                                                                                    className="font-medium text-violet-700 transition hover:text-violet-800"
                                                                                >
                                                                                    {noticeExternalLinkLabel(notice, noticesText)}
                                                                                </a>
                                                                            ) : (
                                                                                <span>{noticesText.notRegistered}</span>
                                                                            )}
                                                                        </div>
                                                                        {notice.notes && !isEditingDeadlines ? (
                                                                            <div className="sm:col-span-2">
                                                                                <span className="font-medium text-slate-700">{noticesText.notesLabel}:</span>{' '}
                                                                                <span className="whitespace-pre-line">{notice.notes}</span>
                                                                            </div>
                                                                        ) : null}
                                                                    </div>
                                                                        ) : (
                                                                            <div className="grid gap-2 sm:grid-cols-2">
                                                                                <div>
                                                                            <span className="font-medium text-slate-700">{noticesText.savedByLabel}:</span>{' '}
                                                                            <span>{notice.saved_by_name || noticesText.notRegistered}</span>
                                                                        </div>
                                                                        {notice.saved_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.savedLabel}:</span>{' '}
                                                                                <span>{formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' })}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.questions_rfi_deadline_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.questionsRfiLabel}:</span>{' '}
                                                                                <span>{formatDate(notice.questions_rfi_deadline_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.rfi_submission_deadline_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.rfiLabel}:</span>{' '}
                                                                                <span>{formatDate(notice.rfi_submission_deadline_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.questions_rfp_deadline_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.questionsRfpLabel}:</span>{' '}
                                                                                <span>{formatDate(notice.questions_rfp_deadline_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.deadline ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.rfpLabel}:</span>{' '}
                                                                                <span>{formatDate(notice.deadline, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.award_date_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.awardLabel}:</span>{' '}
                                                                                <span>{formatDate(notice.award_date_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {isHistoryMode && notice.selected_supplier_name ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.selectedSupplierLabel}:</span>{' '}
                                                                                <span>{notice.selected_supplier_name}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {isHistoryMode && notice.contract_value_mnok !== null && notice.contract_value_mnok !== undefined ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{noticesText.contractValueLabel}:</span>{' '}
                                                                                <span>{formatMnokValue(notice.contract_value_mnok, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {isHistoryMode && historyContractLabel ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{historyContractLabel}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {needsHistorySelection ? (
                                                                            <AlertBox className="sm:col-span-2">
                                                                                {noticesText.historyNeedsSelection}
                                                                            </AlertBox>
                                                                        ) : null}
                                                                        {isHistoryMode && notice.next_process_date_at ? (
                                                                            <div className="rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 sm:col-span-2">
                                                                                <div className="text-base font-medium text-violet-900">{noticesText.nextFollowUpTitle}</div>
                                                                                <div className="mt-1 text-base text-violet-700">{formatDate(notice.next_process_date_at, locale)}</div>
                                                                            </div>
                                                                        ) : isHistoryMode ? (
                                                                            <div className="sm:col-span-2">
                                                                                <span className="font-medium text-slate-700">{noticesText.noPlannedFollowUp}</span>
                                                                            </div>
                                                                        ) : null}
                                                                    </div>
                                                                )}

                                                                {isEditingDeadlines ? (
                                                            <form
                                                                onSubmit={(event) => {
                                                                    event.preventDefault();
                                                                    updateSavedNoticeDeadlines(notice);
                                                                }}
                                                                className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"
                                                            >
                                                                <div className="space-y-3">
                                                                    <label className="space-y-2">
                                                                        <span className="text-base font-medium text-slate-700">{noticesText.deadlineQuestionsRfiLabel}</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.questions_rfi_deadline_at}
                                                                            onChange={(event) => deadlineForm.setData('questions_rfi_deadline_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.questions_rfi_deadline_at ? (
                                                                            <p className="text-base text-rose-600">{deadlineForm.errors.questions_rfi_deadline_at}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-base font-medium text-slate-700">{noticesText.deadlineRfiSubmissionLabel}</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.rfi_submission_deadline_at}
                                                                            onChange={(event) => deadlineForm.setData('rfi_submission_deadline_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.rfi_submission_deadline_at ? (
                                                                            <p className="text-base text-rose-600">{deadlineForm.errors.rfi_submission_deadline_at}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-base font-medium text-slate-700">{noticesText.deadlineQuestionsRfpLabel}</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.questions_rfp_deadline_at}
                                                                            onChange={(event) => deadlineForm.setData('questions_rfp_deadline_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.questions_rfp_deadline_at ? (
                                                                            <p className="text-base text-rose-600">{deadlineForm.errors.questions_rfp_deadline_at}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-base font-medium text-slate-700">{noticesText.deadlineAwardDateLabel}</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.award_date_at}
                                                                            onChange={(event) => deadlineForm.setData('award_date_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.award_date_at ? (
                                                                            <p className="text-base text-rose-600">{deadlineForm.errors.award_date_at}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <div className="rounded-2xl border border-blue-200 bg-blue-50/70 p-4 md:col-span-2">
                                                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                                                            <div>
                                                                                <div className="text-base font-semibold uppercase tracking-[0.1em] text-blue-700">
                                                                                    {noticesText.businessReviewTitle}
                                                                                </div>
                                                                                <p className="mt-1 text-base leading-6 text-blue-950/75">
                                                                                    {noticesText.businessReviewDescription}
                                                                                </p>
                                                                            </div>

                                                                            <button
                                                                                type="button"
                                                                                onClick={addBusinessReview}
                                                                                className="inline-flex items-center justify-center rounded-xl border border-blue-200 bg-white px-4 py-2 text-base font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100"
                                                                            >
                                                                                {noticesText.addBusinessReview}
                                                                            </button>
                                                                        </div>

                                                                        <div className="mt-4 space-y-3">
                                                                            {deadlineForm.data.business_reviews.length > 0 ? (
                                                                                deadlineForm.data.business_reviews.map((review, index) => (
                                                                                    <div
                                                                                        key={review.id ?? `business-review-${index}`}
                                                                                        className="rounded-2xl border border-blue-200 bg-white px-4 py-4"
                                                                                    >
                                                                                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
                                                                                            <label className="min-w-0 flex-1 space-y-2">
                                                                                                <span className="text-base font-medium text-slate-700">
                                                                                                    {noticesText.businessReviewItemLabel} {index + 1}
                                                                                                </span>
                                                                                                <input
                                                                                                    type="date"
                                                                                                    value={review.business_review_at}
                                                                                                    onChange={(event) => updateBusinessReviewAt(index, event.target.value)}
                                                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-100"
                                                                                                />
                                                                                                {deadlineForm.errors[`business_reviews.${index}.business_review_at`] ? (
                                                                                                    <p className="text-base text-rose-600">
                                                                                                        {deadlineForm.errors[`business_reviews.${index}.business_review_at`]}
                                                                                                    </p>
                                                                                                ) : null}
                                                                                            </label>

                                                                                            <button
                                                                                                type="button"
                                                                                                onClick={() => removeBusinessReview(index)}
                                                                                                className="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-base font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                                                            >
                                                                                                {noticesText.delete}
                                                                                            </button>
                                                                                        </div>
                                                                                    </div>
                                                                                ))
                                                                            ) : (
                                                                                <div className="rounded-2xl border border-dashed border-blue-200 bg-white px-4 py-4 text-base leading-6 text-blue-900/70">
                                                                                    {noticesText.businessReviewEmpty}
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <div className="mt-4 flex flex-wrap items-center justify-between gap-2.5">
                                                                    <div className="flex flex-wrap gap-2.5">
                                                                        <button
                                                                            type="submit"
                                                                            disabled={deadlineForm.processing}
                                                                            className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-base font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                        >
                                                                            {deadlineForm.processing ? noticesText.savingLabel : noticesText.saveLabel}
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            onClick={cancelDeadlineEditor}
                                                                            disabled={deadlineForm.processing}
                                                                            className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                                        >
                                                                            {noticesText.cancelLabel}
                                                                        </button>
                                                                    </div>
                                                                    <button
                                                                        type="button"
                                                                        disabled={deadlineForm.processing}
                                                                        onClick={() => deadlineForm.setData((data) => ({
                                                                            ...data,
                                                                            questions_rfi_deadline_at: '',
                                                                            rfi_submission_deadline_at: '',
                                                                            questions_rfp_deadline_at: '',
                                                                            award_date_at: '',
                                                                        }))}
                                                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                    >
                                                                        Nullstill frister
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        ) : null}

                                                        {isEditingHistory ? (
                                                            <form
                                                                onSubmit={(event) => {
                                                                    event.preventDefault();
                                                                    updateHistoryMetadata(notice);
                                                                }}
                                                                className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"
                                                            >
                                                                <div className="space-y-3">
                                                                    <label className="space-y-2">
                                                                        <span className="text-base font-medium text-slate-700">{noticesText.selectedSupplierLabel}</span>
                                                                        <input
                                                                            type="text"
                                                                            value={historyForm.data.selected_supplier_name}
                                                                            onChange={(event) => historyForm.setData('selected_supplier_name', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {historyForm.errors.selected_supplier_name ? (
                                                                            <p className="text-base text-rose-600">{historyForm.errors.selected_supplier_name}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-base font-medium text-slate-700">{noticesText.contractValueLabel}</span>
                                                                        <input
                                                                            type="text"
                                                                            inputMode="decimal"
                                                                            value={formatNumberWithSpaces(historyForm.data.contract_value_mnok)}
                                                                            onChange={(event) => historyForm.setData('contract_value_mnok', parseNumberFromSpaces(event.target.value))}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {historyForm.errors.contract_value_mnok ? (
                                                                            <p className="text-base text-rose-600">{historyForm.errors.contract_value_mnok}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                                                        <div className="space-y-2">
                                                                            <span className="text-base font-medium text-slate-700">{noticesText.procurementTypeLabel}</span>
                                                                            <select
                                                                                value={historyForm.data.procurement_type}
                                                                                onChange={(event) => updateHistoryProcurementType(event.target.value)}
                                                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                            >
                                                                                <option value="">{noticesText.selectProcurementType}</option>
                                                                                {historyProcurementTypeOptions.map((option) => (
                                                                                    <option key={option.value} value={option.value}>
                                                                                        {option.label}
                                                                                    </option>
                                                                                ))}
                                                                            </select>
                                                                            {historyForm.errors.procurement_type ? (
                                                                                <p className="text-base text-rose-600">{historyForm.errors.procurement_type}</p>
                                                                            ) : null}
                                                                        </div>

                                                                        {shouldShowHistoryFollowUpField ? (
                                                                            <div className="space-y-2">
                                                                                <span className="text-base font-medium text-slate-700">{noticesText.followUpLabel}</span>
                                                                                <select
                                                                                    value={historyForm.data.follow_up_mode}
                                                                                    onChange={(event) => updateHistoryFollowUpMode(event.target.value)}
                                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                                >
                                                                                    {historyFollowUpOptions.map((option) => (
                                                                                        <option key={option.value} value={option.value}>
                                                                                            {option.label}
                                                                                        </option>
                                                                                    ))}
                                                                                </select>
                                                                                {historyForm.errors.follow_up_mode ? (
                                                                                    <p className="text-base text-rose-600">{historyForm.errors.follow_up_mode}</p>
                                                                                ) : null}
                                                                            </div>
                                                                        ) : null}

                                                                        {isHistoryFormManualOffset ? (
                                                                            <div className="space-y-2">
                                                                                <span className="text-base font-medium text-slate-700">{noticesText.followUpOffsetMonthsLabel}</span>
                                                                                <input
                                                                                    type="number"
                                                                                    inputMode="numeric"
                                                                                    min="1"
                                                                                    step="1"
                                                                                    value={historyForm.data.follow_up_offset_months}
                                                                                    onChange={(event) => historyForm.setData('follow_up_offset_months', event.target.value)}
                                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                                />
                                                                                {historyForm.errors.follow_up_offset_months ? (
                                                                                    <p className="text-base text-rose-600">{historyForm.errors.follow_up_offset_months}</p>
                                                                                ) : null}
                                                                            </div>
                                                                        ) : null}
                                                                    </div>

                                                                    {isHistoryFormRecurring ? (
                                                                        <label className="space-y-2">
                                                                            <span className="text-base font-medium text-slate-700">{noticesText.contractPeriodMonthsLabel}</span>
                                                                            <input
                                                                                type="number"
                                                                                inputMode="numeric"
                                                                                min="1"
                                                                                step="1"
                                                                                value={historyForm.data.contract_period_months}
                                                                                onChange={(event) => historyForm.setData('contract_period_months', event.target.value)}
                                                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                            />
                                                                            <p className="text-base leading-6 text-slate-600">{noticesText.contractPeriodHelp}</p>
                                                                            {historyForm.errors.contract_period_months ? (
                                                                                <p className="text-base text-rose-600">{historyForm.errors.contract_period_months}</p>
                                                                            ) : null}
                                                                        </label>
                                                                    ) : null}

                                                                    {isHistoryFormManualOffset ? (
                                                                        <div className="rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-base text-violet-800">
                                                                            <div className="font-medium text-violet-900">{noticesText.nextFollowUpTitle}</div>
                                                                            <div className="mt-1 text-base text-violet-700">
                                                                                {historyNextFollowUpPreview ? formatDate(historyNextFollowUpPreview, locale) : noticesText.nextFollowUpManualHint}
                                                                            </div>
                                                                            <p className="mt-1 text-base leading-6 text-violet-700">
                                                                                {noticesText.nextFollowUpManualHelp}
                                                                            </p>
                                                                        </div>
                                                                    ) : null}

                                                                    {shouldShowHistoryFollowUpField && historyForm.data.follow_up_mode === 'none' ? (
                                                                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-base text-slate-600">
                                                                            <span className="font-medium text-slate-700">{noticesText.noPlannedFollowUp}</span>
                                                                        </div>
                                                                    ) : null}
                                                                </div>

                                                                <div className="mt-4 flex flex-wrap gap-2.5">
                                                                    <button
                                                                        type="submit"
                                                                        disabled={historyForm.processing}
                                                                        className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-base font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                    >
                                                                        {historyForm.processing ? noticesText.savingLabel : noticesText.saveLabel}
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={cancelHistoryEditor}
                                                                        disabled={historyForm.processing}
                                                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                                    >
                                                                        {noticesText.cancelLabel}
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        ) : null}
                                                    </div>
                                                ) : null}
                                            </article>
                                        );
                                    })}
                                </div>
                            )}
                        </section>

                        <div className="flex flex-col gap-4 rounded-[20px] border border-slate-200 bg-white px-5 py-4 text-base text-slate-600 shadow-[0_8px_22px_rgba(15,23,42,0.04)] sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                    {notices.meta.from && notices.meta.to
                                    ? `${formatInteger(notices.meta.from, locale)}–${formatInteger(notices.meta.to, locale)} av ${formatInteger(notices.meta.total, locale)} ${noticesText.hitsSuffix}`
                                    : `${formatInteger(notices.meta.total ?? 0, locale)} ${noticesText.hitsSuffix}`}
                            </div>
                            <div className="flex gap-3">
                                <button
                                    type="button"
                                    disabled={!notices.meta.prev_page_url}
                                    onClick={() => goToNoticePage(notices.meta.prev_page_url)}
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {common.previous}
                                </button>
                                <button
                                    type="button"
                                    disabled={!notices.meta.next_page_url}
                                    onClick={() => goToNoticePage(notices.meta.next_page_url)}
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {common.next}
                                </button>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </CustomerAppLayout>
    );
}
