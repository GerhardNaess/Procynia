import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CpvSelector from './CpvSelector';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import DiscoveryNoticeCard from '../../../Components/App/DiscoveryNoticeCard';

const publicationOptions = [
    { value: '', label: 'Alltid' },
    { value: '7', label: 'Siste 7 dager' },
    { value: '30', label: 'Siste 30 dager' },
    { value: '90', label: 'Siste 90 dager' },
    { value: '365', label: 'Siste 365 dager' },
];

const statusOptions = [
    { value: '', label: 'Alle statuser' },
    { value: 'ACTIVE', label: 'Aktiv' },
    { value: 'EXPIRED', label: 'Utgått' },
    { value: 'AWARDED', label: 'Tildelt' },
    { value: 'CANCELLED', label: 'Avlyst' },
];

const relevanceOptions = [
    { value: '', label: 'Alle nivåer' },
    { value: 'high', label: 'Høy relevans' },
    { value: 'medium', label: 'Middels relevans' },
    { value: 'low', label: 'Lav relevans' },
];

const historyProcurementTypeOptions = [
    { value: 'one_time', label: 'Engangsanskaffelse' },
    { value: 'recurring', label: 'Løpende avtale' },
];

const historyFollowUpOptions = [
    { value: 'none', label: 'Ingen oppfølging' },
    { value: 'manual_offset', label: 'Varsle etter antall måneder' },
];

const bidStatusOptions = [
    { value: '', label: 'Alle bid-statuser' },
    { value: 'discovered', label: 'Registrert' },
    { value: 'qualifying', label: 'Kvalifiseres' },
    { value: 'go_no_go', label: 'Go / No-Go' },
    { value: 'in_progress', label: 'Under arbeid' },
    { value: 'submitted', label: 'Sendt' },
    { value: 'negotiation', label: 'Forhandling' },
    { value: 'won', label: 'Vunnet' },
    { value: 'lost', label: 'Tapt' },
    { value: 'no_go', label: 'No-Go' },
    { value: 'withdrawn', label: 'Trukket' },
    { value: 'archived', label: 'Arkiv' },
];

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

function BellIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M10 17a2 2 0 0 0 1.9-1.4" strokeLinecap="round" />
            <path
                d="M5.5 8.2a4.5 4.5 0 1 1 9 0v2.2c0 .9.3 1.7.9 2.4l.3.4a.8.8 0 0 1-.6 1.3H4a.8.8 0 0 1-.6-1.3l.3-.4c.6-.7.9-1.5.9-2.4V8.2Z"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function ListIcon(props) {
    return (
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...props}>
            <path d="M7 5h9" strokeLinecap="round" />
            <path d="M7 10h9" strokeLinecap="round" />
            <path d="M7 15h9" strokeLinecap="round" />
            <path d="M4 5h.01" strokeLinecap="round" />
            <path d="M4 10h.01" strokeLinecap="round" />
            <path d="M4 15h.01" strokeLinecap="round" />
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

function historyMonthLabel(months) {
    return `${months} ${Number(months) === 1 ? 'måned' : 'måneder'}`;
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

function historyContractSummary(notice) {
    const selection = deriveHistorySelection(notice);

    if (selection.procurementType === 'recurring' && notice.contract_period_months) {
        return `Avtaleperiode: ${historyMonthLabel(notice.contract_period_months)}`;
    }

    if (notice.contract_period_text) {
        return `Avtaleperiode: ${notice.contract_period_text}`;
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

function pluralize(total, locale) {
    const normalized = normalizeCount(total);

    return `${formatInteger(normalized, locale)} ${normalized === 1 ? 'anskaffelse' : 'kunngjøringer'}`;
}

function summarizeText(value) {
    const trimmed = (value ?? '').trim();

    if (trimmed === '') {
        return 'Kort beskrivelse kommer i neste steg.';
    }

    return trimmed;
}

function statusBadge(status, deadline) {
    if (status) {
        return {
            label: status,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (!deadline) {
        return {
            label: 'Kunngjøring',
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (new Date(deadline) <= new Date()) {
        return {
            label: 'Utgått',
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        };
    }

    return {
        label: 'Aktiv',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    };
}

function noticeSourceTypeLabel(notice) {
    if (notice.source_type_label) {
        return notice.source_type_label;
    }

    return notice.source_type === 'private_request'
        ? 'Privat forespørsel'
        : 'Offentlig kunngjøring';
}

function noticeSourceTimelineLabel(notice, locale) {
    if (notice.source_type === 'private_request') {
        return `Registrert ${formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' })}`;
    }

    return `Publisert ${formatDate(notice.publication_date, locale)}`;
}

function noticeExternalLinkLabel(notice) {
    return notice.source_type === 'private_request'
        ? 'Åpne lenke'
        : 'Åpne i Doffin';
}

function noticeSourceBadgeClassName(notice) {
    return notice.source_type === 'private_request'
        ? 'bg-violet-100 text-violet-700 ring-violet-200'
        : 'bg-slate-100 text-slate-700 ring-slate-200';
}

function savedNoticeDeadlineBadge(notice, locale) {
    if (notice.source_type === 'private_request') {
        if (!notice.deadline) {
            return {
                label: 'Frist ikke registrert',
                className: 'bg-slate-100 text-slate-700 ring-slate-200',
            };
        }

        if (new Date(notice.deadline) <= new Date()) {
            return {
                label: 'Frist utløpt',
                className: 'bg-rose-100 text-rose-700 ring-rose-200',
            };
        }

        return {
            label: `Frist ${formatDate(notice.deadline, locale)}`,
            className: 'bg-violet-100 text-violet-700 ring-violet-200',
        };
    }

    if (notice.deadline_state === 'upcoming' && notice.next_deadline_type && notice.next_deadline_at) {
        return {
            label: `Frist ${notice.next_deadline_type}: ${formatDeadlineDate(notice.next_deadline_at)}`,
            className: 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    if (notice.deadline_state === 'expired') {
        return {
            label: 'Frist utløpt',
            className: 'bg-rose-100 text-rose-700 ring-rose-200',
        };
    }

    return {
        label: 'Frist mangler metadata',
        className: 'bg-rose-100 text-rose-700 ring-rose-200',
    };
}

function savedNoticeTimelineSteps(notice) {
    return [
        { key: 'questions_rfi', label: 'Spm RFI', date: notice.questions_rfi_deadline_at },
        { key: 'rfi', label: 'RFI', date: notice.rfi_submission_deadline_at },
        { key: 'questions_rfp', label: 'Spm RFP', date: notice.questions_rfp_deadline_at },
        { key: 'rfp', label: 'RFP', date: notice.rfp_submission_deadline_at },
        { key: 'award', label: 'Tildeling', date: notice.award_date_at },
    ];
}

function privateRequestSummaryFields(notice, locale) {
    return [
        {
            key: 'saved_at',
            label: 'Registrert',
            value: notice.saved_at ? formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' }) : 'Ikke registrert',
        },
        {
            key: 'buyer_name',
            label: 'Oppdragsgiver',
            value: notice.buyer_name || 'Ikke registrert',
        },
        {
            key: 'deadline',
            label: 'Frist',
            value: notice.deadline ? formatDate(notice.deadline, locale) : 'Ikke registrert',
        },
        {
            key: 'reference_number',
            label: 'Referanse',
            value: notice.reference_number || 'Ikke registrert',
        },
        {
            key: 'contact_person_name',
            label: 'Kontaktperson',
            value: notice.contact_person_name || 'Ikke registrert',
        },
        {
            key: 'contact_person_email',
            label: 'Kontakt e-post',
            value: notice.contact_person_email || 'Ikke registrert',
        },
        {
            key: 'notes',
            label: 'Notater',
            value: notice.notes || 'Ingen notater registrert',
            span: true,
        },
    ];
}

function emptyStateContent(mode, hasAppliedSearch, hasAppliedRefinements) {
    if (mode === 'saved') {
        return {
            title: 'Ingen lagrede kunngjøringer ennå.',
            body: 'Lagre kunngjøringer fra live Doffin-søk for å holde dem synlige her.',
        };
    }

    if (mode === 'history') {
        return {
            title: 'Ingen kunngjøringer i historikk ennå.',
            body: 'Flytt lagrede kunngjøringer hit når de ikke lenger skal ligge i arbeidslisten.',
        };
    }

    return {
        title: hasAppliedSearch || hasAppliedRefinements
            ? 'Ingen Doffin-treff matcher søket ditt.'
            : 'Ingen Doffin-treff er tilgjengelige akkurat nå.',
        body: hasAppliedRefinements
            ? 'Prøv et bredere søk eller fjern noen av filtrene.'
            : 'Søk i tittel, oppdragsgiver eller organisasjonsnummer for å finne kunngjøringer direkte i Doffin.',
    };
}

export default function NoticeIndex({ notices, filters, savedSearches = [], source, supportMode, cpvSelector, mode = 'live', worklist = {}, monitoring = {} }) {
    const { auth, locale, translations } = usePage().props;
    const [selectedWatchListId, setSelectedWatchListId] = useState('');
    const [searchQuery, setSearchQuery] = useState(filters.q ?? '');
    const [organizationName, setOrganizationName] = useState(filters.organization_name ?? '');
    const [selectedCpvItems, setSelectedCpvItems] = useState(cpvSelector?.selected ?? []);
    const [keywords, setKeywords] = useState(filters.keywords ?? '');
    const [publicationDate, setPublicationDate] = useState(filters.publication_period ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [bidStatusFilter, setBidStatusFilter] = useState(filters.bid_status ?? '');
    const [relevance, setRelevance] = useState(filters.relevance ?? '');
    const [expandedSavedNoticeIds, setExpandedSavedNoticeIds] = useState({});
    const [expandedNoticeSummaryIds, setExpandedNoticeSummaryIds] = useState({});
    const [editingSavedNoticeId, setEditingSavedNoticeId] = useState(null);
    const [editingHistoryNoticeId, setEditingHistoryNoticeId] = useState(null);
    const [isPrivateRequestFormOpen, setIsPrivateRequestFormOpen] = useState(false);
    const deadlineForm = useForm({
        questions_rfi_deadline_at: '',
        rfi_submission_deadline_at: '',
        questions_rfp_deadline_at: '',
        rfp_submission_deadline_at: '',
        award_date_at: '',
    });
    const historyForm = useForm({
        selected_supplier_name: '',
        contract_value_mnok: '',
        procurement_type: '',
        follow_up_mode: '',
        follow_up_offset_months: '',
        contract_period_months: '',
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
    const isSavedOrHistoryMode = mode === 'saved' || mode === 'history';
    const hasAppliedSearch = (filters.q ?? '').trim() !== '';
    const hasAppliedRefinements = [filters.organization_name, filters.cpv, filters.keywords, filters.publication_period, filters.status].some(
        (value) => (value ?? '').trim() !== '',
    );
    const emptyState = emptyStateContent(mode, hasAppliedSearch, hasAppliedRefinements);
    const worklistSummary = [
        { key: 'saved', label: 'Registrerte kunngjøringer', count: worklist?.saved_count ?? 0 },
        { key: 'history', label: 'Historikk', count: worklist?.history_count ?? 0 },
    ];
    const canManageWatchProfiles = Boolean(auth?.user?.can_manage_watch_profiles);
    const monitoringHitsCount = Number(monitoring?.new_hits_last_day_count ?? 0);
    const monitoringHitsLabel = monitoringHitsCount === 1 ? '1 nytt treff siste døgn' : `${monitoringHitsCount} nye treff siste døgn`;
    const monitoringNextUpdateText = monitoring?.next_update_text ?? 'Automatisk oppdatering er ikke aktiv ennå.';
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
    const isCappedLiveSearch = isLiveMode && Boolean(notices?.meta?.is_capped) && totalHits > accessibleHits;

    useEffect(() => {
        setSearchQuery(filters.q ?? '');
        setOrganizationName(filters.organization_name ?? '');
        setSelectedCpvItems(cpvSelector?.selected ?? []);
        setKeywords(filters.keywords ?? '');
        setPublicationDate(filters.publication_period ?? '');
        setStatus(filters.status ?? '');
        setBidStatusFilter(filters.bid_status ?? '');
        setRelevance(filters.relevance ?? '');
    }, [
        filters.bid_status,
        cpvSelector?.selected,
        filters.keywords,
        filters.organization_name,
        filters.publication_period,
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

    const visitMode = (nextMode) => {
        router.get(
            '/app/notices',
            buildNoticeQuery({
                mode: nextMode,
                q: filters.q,
                organization_name: filters.organization_name,
                cpv: filters.cpv,
                keywords: filters.keywords,
                publication_period: filters.publication_period,
                status: filters.status,
                relevance: filters.relevance,
                bid_status: filters.bid_status,
            }),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
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

    const archiveNotice = (notice) => {
        router.patch(`/app/notices/saved/${notice.saved_notice_id}/archive`, {}, {
            preserveScroll: true,
        });
    };

    const removeNotice = (notice) => {
        router.delete(`/app/notices/saved/${notice.saved_notice_id}`, {
            preserveScroll: true,
        });
    };

    const removeHistoryNotice = (notice) => {
        if (!window.confirm('Er du sikker på at du vil slette denne historikk-kunngjøringen?\n\nDenne handlingen kan ikke angres.')) {
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
            rfp_submission_deadline_at: dateInputValue(notice.rfp_submission_deadline_at),
            award_date_at: dateInputValue(notice.award_date_at),
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

    const applyFilters = () => {
        router.get(
            '/app/notices',
            buildNoticeQuery({
                mode: 'live',
                q: searchQuery,
                organization_name: organizationName,
                cpv: selectedCpvItems.map((item) => item.code).join(','),
                keywords,
                publication_period: publicationDate,
                status,
            }),
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const clearFilters = () => {
        setSelectedWatchListId('');
        setSearchQuery('');
        setOrganizationName('');
        setSelectedCpvItems([]);
        setKeywords('');
        setPublicationDate('');
        setStatus('');
        setBidStatusFilter('');
        setRelevance('');

        router.get(
            '/app/notices',
            {
                mode: 'live',
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const applySavedNoticeStatusFilter = (nextBidStatus) => {
        setBidStatusFilter(nextBidStatus);

        router.get(
            '/app/notices',
            buildNoticeQuery({
                mode,
                q: filters.q,
                organization_name: filters.organization_name,
                cpv: filters.cpv,
                keywords: filters.keywords,
                publication_period: filters.publication_period,
                status: filters.status,
                relevance: filters.relevance,
                bid_status: nextBidStatus,
            }),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const applyWatchListPrefill = (watchListId) => {
        setSelectedWatchListId(watchListId);

        if (watchListId === '') {
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
        }

        if (typeof prefill.publication_period === 'string') {
            setPublicationDate(prefill.publication_period);
        }

        if (typeof prefill.status === 'string') {
            setStatus(prefill.status);
        }

        if (typeof prefill.relevance === 'string') {
            setRelevance(prefill.relevance);
        }
    };

    const pageTitle = isLiveMode ? 'Kunngjøringer' : 'Arbeidsliste';
    const pageHeading = isLiveMode ? translations.frontend.procurements_nav : 'Arbeidsliste';
    const pageSubtitle = isLiveMode
        ? translations.frontend.procurements_subtitle
        : 'Oversikt over kunngjøringer du følger opp og jobber aktivt med.';


    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{pageHeading}</h1>
                    <p className="text-[15px] text-slate-500">{pageSubtitle}</p>
                </section>

                <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_308px] xl:items-start">
                    <div className="space-y-5">
                        {isLiveMode ? (
                            <>
                                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)] sm:p-5">
                            <div className="mb-3">
                                <div className="text-sm font-medium text-slate-900">Live søk i Doffin</div>
                                <p className="mt-1 text-sm text-slate-500">
                                    Søk direkte i Doffin etter tittel, oppdragsgiver, organisasjonsnummer og beskrivelse.
                                </p>
                            </div>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    applyFilters();
                                }}
                                className="flex flex-col gap-2.5 lg:flex-row"
                            >
                                <label className="relative flex-1 overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                    <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                                    <input
                                        type="search"
                                        value={searchQuery}
                                        onChange={(event) => setSearchQuery(event.target.value)}
                                        placeholder="Søk i tittel, oppdragsgiver, org.nr. eller beskrivelse"
                                        className="h-[54px] w-full border-0 bg-transparent pl-12 pr-4 text-[15px] text-slate-900 outline-none placeholder:text-slate-400 focus:ring-0"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    className="inline-flex h-[54px] items-center justify-center gap-2 rounded-2xl bg-violet-600 px-6 text-sm font-semibold text-white transition hover:bg-violet-700"
                                >
                                    <SearchIcon className="h-4 w-4" />
                                    {translations.frontend.search_button}
                                </button>
                            </form>
                        </section>

                        <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                            <div className="mb-4">
                                <div className="flex items-center gap-2.5">
                                    <FilterIcon className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-xl font-semibold text-slate-950">Filtrer Doffin-treff</h2>
                                </div>
                                <p className="mt-1 text-sm text-slate-500">
                                    Bruk filtrene under for å snevre inn live-resultatene fra Doffin.
                                </p>
                            </div>

                            <div className="space-y-3.5">
                                <label className="space-y-2">
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-sm font-medium text-slate-700">Watch list</span>
                                        {activeWatchList ? (
                                            <span className="inline-flex items-center rounded-full bg-violet-50 px-2.5 py-1 text-[11px] font-medium text-violet-700 ring-1 ring-inset ring-violet-200">
                                                Aktiv
                                            </span>
                                        ) : null}
                                    </div>
                                    <select
                                        value={selectedWatchListId}
                                        disabled={watchListOptions.length === 0}
                                        onChange={(event) => applyWatchListPrefill(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400"
                                    >
                                        <option value="">{watchListOptions.length === 0 ? 'Ingen watch lists tilgjengelig' : 'Ingen watch list'}</option>
                                        {watchListOptions.map((item) => (
                                            <option key={item.value} value={item.value}>
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                    {activeWatchList ? (
                                        <div className="rounded-xl border border-violet-200 bg-violet-50/70 px-3 py-2.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">Aktiv watch list</span>
                                                <span className="text-sm font-medium text-violet-900">{activeWatchList.label}</span>
                                            </div>
                                            <p className="mt-1 text-xs leading-5 text-violet-800">
                                                Valgt watch list fyller inn filtrene automatisk. Du kan fortsatt justere feltene manuelt.
                                            </p>
                                        </div>
                                    ) : (
                                        <p className="text-xs text-slate-400">
                                            {watchListOptions.length === 0
                                                ? 'Ingen watch lists er tilgjengelige for kunden akkurat nå.'
                                                : 'Velg en watch list for å fylle inn filtrene under. Du kan fortsatt redigere alle felter manuelt etterpå.'}
                                        </p>
                                    )}
                                </label>

                                <div className="grid gap-3.5 md:grid-cols-2 xl:grid-cols-3">
                                    <label className="space-y-2">
                                        <span className="text-sm font-medium text-slate-700">{translations.frontend.organization_name}</span>
                                        <input
                                            type="text"
                                            value={organizationName}
                                            onChange={(event) => setOrganizationName(event.target.value)}
                                            placeholder="Skriv organisasjonsnavn eller org.nr."
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        />
                                    </label>
                                    <CpvSelector
                                        endpoint={cpvSelector?.endpoint ?? '/app/notices/cpv-suggestions'}
                                        selectedItems={selectedCpvItems}
                                        onSelectedItemsChange={setSelectedCpvItems}
                                        popularItems={cpvSelector?.popular ?? []}
                                    />
                                    <label className="space-y-2">
                                        <span className="text-sm font-medium text-slate-700">{translations.frontend.keyword}</span>
                                        <input
                                            type="text"
                                            value={keywords}
                                            onChange={(event) => setKeywords(event.target.value)}
                                            placeholder="For eksempel havn, ferge, drift"
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        />
                                        <p className="text-xs text-slate-400">Kommaseparer ord for å snevre inn hovedsøket.</p>
                                    </label>
                                    <label className="space-y-2">
                                        <span className="text-sm font-medium text-slate-700">{translations.frontend.publish_date}</span>
                                        <select
                                            value={publicationDate}
                                            onChange={(event) => setPublicationDate(event.target.value)}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        >
                                            {publicationOptions.map((option) => (
                                                <option key={option.value || 'empty'} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="space-y-2">
                                        <span className="text-sm font-medium text-slate-700">{translations.common.status}</span>
                                        <select
                                            value={status}
                                            onChange={(event) => setStatus(event.target.value)}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                        >
                                            {statusOptions.map((option) => (
                                                <option key={option.value || 'empty'} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="space-y-2">
                                        <span className="text-sm font-medium text-slate-700">{translations.frontend.relevance}</span>
                                        <select
                                            value={relevance}
                                            onChange={(event) => setRelevance(event.target.value)}
                                            disabled
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-400 outline-none transition disabled:cursor-not-allowed"
                                        >
                                            {relevanceOptions.map((option) => (
                                                <option key={option.value || 'empty'} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="text-xs text-slate-400">Relevans finnes ikke som live Doffin-filter i denne flyten.</p>
                                    </label>
                                </div>
                            </div>

                            <div className="mt-5 flex flex-wrap gap-2.5">
                                <button
                                    type="button"
                                    onClick={applyFilters}
                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                                >
                                    {translations.frontend.apply_filters}
                                </button>
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {translations.frontend.clear_filters}
                                </button>
                            </div>
                        </section>

                            </>
                        ) : null}

                        {supportMode?.active && supportMode?.message ? (
                            <section className="rounded-[20px] border border-amber-200 bg-amber-50 px-5 py-4 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="text-sm font-medium text-amber-900">{translations.frontend.support_mode_label}</div>
                                <p className="mt-1 text-sm leading-6 text-amber-800">{supportMode.message}</p>
                            </section>
                        ) : null}

                        {isSavedMode ? (
                            <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="space-y-4">
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <div className="text-sm font-medium text-slate-900">Registrer privat forespørsel</div>
                                            <p className="mt-1 text-sm text-slate-500">
                                                Legg inn inviterte eller direkte mottatte forespørsler i samme saksmotor som offentlige kunngjøringer.
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            aria-expanded={isPrivateRequestFormOpen}
                                            aria-controls="private-request-form"
                                            onClick={() => setIsPrivateRequestFormOpen((current) => !current)}
                                            className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                        >
                                            {isPrivateRequestFormOpen ? 'Skjul skjema' : 'Registrer privat forespørsel'}
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
                                                    <span className="text-sm font-medium text-slate-700">Tittel</span>
                                                    <input
                                                        type="text"
                                                        value={privateRequestForm.data.title}
                                                        onChange={(event) => privateRequestForm.setData('title', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder="Navn på forespørselen"
                                                    />
                                                    {privateRequestForm.errors.title ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.title}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-sm font-medium text-slate-700">Oppdragsgiver</span>
                                                    <input
                                                        type="text"
                                                        value={privateRequestForm.data.buyer_name}
                                                        onChange={(event) => privateRequestForm.setData('buyer_name', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder="Navn på kunde eller oppdragsgiver"
                                                    />
                                                    {privateRequestForm.errors.buyer_name ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.buyer_name}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-sm font-medium text-slate-700">Frist</span>
                                                    <input
                                                        type="date"
                                                        value={privateRequestForm.data.deadline}
                                                        onChange={(event) => privateRequestForm.setData('deadline', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                    />
                                                    {privateRequestForm.errors.deadline ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.deadline}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2 md:col-span-2">
                                                    <span className="text-sm font-medium text-slate-700">Kort beskrivelse</span>
                                                    <textarea
                                                        value={privateRequestForm.data.summary}
                                                        onChange={(event) => privateRequestForm.setData('summary', event.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder="Kort om hva forespørselen gjelder"
                                                    />
                                                    {privateRequestForm.errors.summary ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.summary}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-sm font-medium text-slate-700">Referanse</span>
                                                    <input
                                                        type="text"
                                                        value={privateRequestForm.data.reference_number}
                                                        onChange={(event) => privateRequestForm.setData('reference_number', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder="Valgfri referanse"
                                                    />
                                                    {privateRequestForm.errors.reference_number ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.reference_number}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-sm font-medium text-slate-700">Kontaktperson</span>
                                                    <input
                                                        type="text"
                                                        value={privateRequestForm.data.contact_person_name}
                                                        onChange={(event) => privateRequestForm.setData('contact_person_name', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder="Navn på kontaktperson"
                                                    />
                                                    {privateRequestForm.errors.contact_person_name ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.contact_person_name}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2">
                                                    <span className="text-sm font-medium text-slate-700">Kontakt e-post</span>
                                                    <input
                                                        type="email"
                                                        value={privateRequestForm.data.contact_person_email}
                                                        onChange={(event) => privateRequestForm.setData('contact_person_email', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder="kontakt@kunde.no"
                                                    />
                                                    {privateRequestForm.errors.contact_person_email ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.contact_person_email}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2 md:col-span-2">
                                                    <span className="text-sm font-medium text-slate-700">Ekstern lenke</span>
                                                    <input
                                                        type="url"
                                                        value={privateRequestForm.data.external_url}
                                                        onChange={(event) => privateRequestForm.setData('external_url', event.target.value)}
                                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder="https://..."
                                                    />
                                                    {privateRequestForm.errors.external_url ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.external_url}</p>
                                                    ) : null}
                                                </label>

                                                <label className="space-y-2 md:col-span-2">
                                                    <span className="text-sm font-medium text-slate-700">Notater</span>
                                                    <textarea
                                                        value={privateRequestForm.data.notes}
                                                        onChange={(event) => privateRequestForm.setData('notes', event.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                        placeholder="Valgfri intern informasjon"
                                                    />
                                                    {privateRequestForm.errors.notes ? (
                                                        <p className="text-sm text-rose-600">{privateRequestForm.errors.notes}</p>
                                                    ) : null}
                                                </label>
                                            </div>

                                            <div className="flex flex-wrap gap-2.5">
                                                <button
                                                    type="submit"
                                                    disabled={privateRequestForm.processing}
                                                    className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {privateRequestForm.processing ? 'Lagrer...' : 'Registrer forespørsel'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        privateRequestForm.reset();
                                                        privateRequestForm.clearErrors();
                                                    }}
                                                    disabled={privateRequestForm.processing}
                                                    className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    Tøm
                                                </button>
                                            </div>
                                        </form>
                                    ) : (
                                        <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                            Skjemaet er skjult. Klikk for å registrere en privat forespørsel.
                                        </div>
                                    )}
                                </div>
                            </section>
                        ) : null}

                        {isSavedOrHistoryMode ? (
                            <section className="rounded-[22px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                    <div>
                                        <div className="text-sm font-medium text-slate-900">Fasefilter</div>
                                        <p className="mt-1 text-sm text-slate-500">
                                            Filtrer arbeidslisten etter fase for å finne riktige saker raskere.
                                        </p>
                                    </div>

                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                        <label className="space-y-2">
                                            <span className="text-sm font-medium text-slate-700">Filtrer på fase</span>
                                            <select
                                                value={bidStatusFilter}
                                                onChange={(event) => applySavedNoticeStatusFilter(event.target.value)}
                                                className="h-11 min-w-[240px] rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                            >
                                                {bidStatusOptions.map((option) => (
                                                    <option key={option.value || 'empty'} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        {bidStatusFilter ? (
                                            <button
                                                type="button"
                                                onClick={() => applySavedNoticeStatusFilter('')}
                                                className="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                            >
                                                Fjern filter
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                            </section>
                        ) : null}

                        <section className="space-y-3.5">
                            <div className="space-y-1">
                                <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                    {source?.label}
                                </div>
                                <div className="text-[17px] font-semibold text-slate-950">{pluralize(notices.meta.total ?? 0, locale)}</div>
                            </div>

                            {isCappedLiveSearch ? (
                                <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                                    Doffin rapporterer {formatInteger(totalHits, locale)} treff for dette søket. I denne søkeflyten er bare topp{' '}
                                    {formatInteger(accessibleHits, locale)} treff tilgjengelige for sidevisning. Bruk filtre for å snevre inn treffene og få tilgang til flere relevante kunngjøringer.
                                </div>
                            ) : null}

                            {notices.data.length === 0 ? (
                                <div className="rounded-[22px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                                    <div className="text-lg font-semibold text-slate-900">{emptyState.title}</div>
                                    <p className="mt-2 text-sm text-slate-500">{emptyState.body}</p>
                                </div>
                            ) : (
                                <div className="space-y-3.5">
                                    {notices.data.map((notice) => {
                                        const isPrivateRequest = notice.source_type === 'private_request';
                                        const statusTag = statusBadge(notice.status, notice.deadline);
                                        const deadlineBadge = isSavedOrHistoryMode
                                            ? savedNoticeDeadlineBadge(notice, locale)
                                            : {
                                                label: `Frist ${formatDate(notice.deadline, locale)}`,
                                                className: 'bg-slate-100 text-slate-700 ring-slate-200',
                                            };
                                        const timelineSteps = isSavedOrHistoryMode && !isPrivateRequest ? savedNoticeTimelineSteps(notice) : [];
                                        const isDetailsExpanded = Boolean(expandedSavedNoticeIds[notice.id]);
                                        const isEditingDeadlines = isSavedMode && editingSavedNoticeId === notice.id;
                                        const isEditingHistory = isHistoryMode && editingHistoryNoticeId === notice.id;
                                        const historyContractLabel = isHistoryMode ? historyContractSummary(notice) : null;
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
                                                    actions={
                                                        notice.external_url ? (
                                                            <a
                                                                href={notice.external_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex min-w-[108px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                Åpne i Doffin
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

                                                        <div className="mt-1.5 flex flex-wrap items-center gap-4 text-sm text-slate-600">
                                                            <span className="inline-flex items-center gap-2">
                                                                <BuildingIcon className="h-4 w-4 text-slate-400" />
                                                                {notice.buyer_name || 'Oppdragsgiver ikke angitt'}
                                                            </span>
                                                        </div>

                                                        <div className="mt-3 max-w-4xl text-sm leading-7 text-slate-600 whitespace-pre-line">
                                                            <div style={noticeSummaryStyle}>{summarizeText(notice.summary)}</div>
                                                            {isNoticeSummaryExpandable ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setExpandedNoticeSummaryIds((current) => ({
                                                                        ...current,
                                                                        [notice.id]: !current[notice.id],
                                                                    }))}
                                                                    className="mt-2 text-sm font-medium text-violet-700 transition hover:text-violet-800"
                                                                >
                                                                    {isNoticeSummaryExpanded ? 'Vis mindre' : 'Mer'}
                                                                </button>
                                                            ) : null}
                                                        </div>

                                                        {isSavedOrHistoryMode ? (
                                                            isPrivateRequest ? (
                                                                <div className="mt-4 rounded-2xl border border-violet-200 bg-violet-50/70 px-4 py-4">
                                                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                                                        <div>
                                                                            <div className="text-xs font-semibold uppercase tracking-[0.14em] text-violet-700">
                                                                                Privat forespørsel
                                                                            </div>
                                                                            <p className="mt-1 text-sm text-violet-950/75">
                                                                                Saksinformasjon for en manuelt registrert forespørsel.
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                    <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                                                                        {privateRequestSummaryFields(notice, locale).map((field) => (
                                                                            <div
                                                                                key={field.key}
                                                                                className={classNames(
                                                                                    'rounded-xl bg-white px-3 py-2.5 shadow-[0_1px_0_rgba(15,23,42,0.03)]',
                                                                                    field.span ? 'sm:col-span-2' : '',
                                                                                )}
                                                                            >
                                                                                <dt className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                                                    {field.label}
                                                                                </dt>
                                                                                <dd className="mt-1 text-sm font-medium leading-6 text-slate-900">
                                                                                    {field.value}
                                                                                </dd>
                                                                            </div>
                                                                        ))}
                                                                    </dl>
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
                                                                                    <div className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">
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
                                                                                    <div className={classNames('mt-2 text-xs', isActive ? 'text-slate-700' : 'text-slate-400')}>
                                                                                        {isActive ? formatDeadlineDate(step.date) : '—'}
                                                                                    </div>
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                </div>
                                                            ) : null
                                                        ) : null}

                                                        <div className="mt-4 flex flex-wrap gap-2">
                                                            <span
                                                                className={classNames(
                                                                    'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                                                                    noticeSourceBadgeClassName(notice),
                                                                )}
                                                            >
                                                                <CalendarIcon className="h-3.5 w-3.5" />
                                                                {noticeSourceTypeLabel(notice)}
                                                            </span>
                                                            <span className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                                                <CalendarIcon className="h-3.5 w-3.5" />
                                                                {noticeSourceTimelineLabel(notice, locale)}
                                                            </span>
                                                            <span
                                                                className={classNames(
                                                                    'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                                                                    deadlineBadge.className,
                                                                )}
                                                            >
                                                                <ClockIcon className="h-3.5 w-3.5" />
                                                                {deadlineBadge.label}
                                                            </span>
                                                            {isPrivateRequest ? (
                                                                <span className="inline-flex items-center gap-2 rounded-full bg-violet-50 px-3 py-1.5 text-xs font-medium text-violet-700 ring-1 ring-inset ring-violet-200">
                                                                    {notice.reference_number ? `Referanse: ${notice.reference_number}` : 'Privat forespørsel'}
                                                                </span>
                                                            ) : (
                                                                <>
                                                                    <span className="inline-flex items-center gap-2 rounded-full bg-blue-100 px-3 py-1.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200">
                                                                        Publisert {formatDate(notice.publication_date, locale)}
                                                                    </span>
                                                                    <span className="inline-flex items-center gap-2 rounded-full bg-blue-100 px-3 py-1.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200">
                                                                        {notice.cpv_code ? `CPV: ${notice.cpv_code}` : 'Kategori: Doffin-kunngjøring'}
                                                                    </span>
                                                                </>
                                                            )}
                                                            {isSavedOrHistoryMode && notice.bid_status_label ? (
                                                                <span
                                                                    className={classNames(
                                                                        'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                                                                        bidStatusBadgeClassName(notice.bid_status),
                                                                    )}
                                                                >
                                                                    {notice.bid_status_label}
                                                                </span>
                                                            ) : null}
                                                            {isSavedOrHistoryMode && notice.opportunity_owner_name ? (
                                                                <span className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                                                    Kommersiell eier: {notice.opportunity_owner_name}
                                                                </span>
                                                            ) : null}
                                                            {isSavedOrHistoryMode && notice.submissions_count > 0 ? (
                                                                <span className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                                                    {notice.submissions_count} innsendinger
                                                                </span>
                                                            ) : null}
                                                            {isLiveMode || notice.status ? (
                                                                <span
                                                                    className={classNames(
                                                                        'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset',
                                                                        statusTag.className,
                                                                    )}
                                                                >
                                                                    {statusTag.label}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                    </div>

                                                    <div className="flex shrink-0 flex-row gap-3 lg:flex-col">
                                                        {isSavedOrHistoryMode && notice.show_url ? (
                                                            <Link
                                                                href={notice.show_url}
                                                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                Åpne sak
                                                            </Link>
                                                        ) : null}
                                                        {isSavedOrHistoryMode ? (
                                                            <button
                                                                type="button"
                                                                aria-expanded={isDetailsExpanded}
                                                                onClick={() => {
                                                                    if (isHistoryMode) {
                                                                        toggleHistoryEditor(notice);

                                                                        return;
                                                                    }

                                                                    toggleSavedNoticeDetails(notice.id);
                                                                }}
                                                                className={classNames(
                                                                    'inline-flex min-w-[132px] items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
                                                                    isDetailsExpanded
                                                                        ? 'border-slate-300 bg-slate-100 text-slate-900'
                                                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                                                )}
                                                            >
                                                                {isHistoryMode ? 'Legg til informasjon' : '...'}
                                                            </button>
                                                        ) : null}
                                                        {isSavedMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => openDeadlineEditor(notice)}
                                                                className={classNames(
                                                                    'inline-flex min-w-[132px] items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
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
                                                                    'inline-flex min-w-[132px] items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
                                                                    notice.is_saved
                                                                        ? 'cursor-not-allowed border-emerald-200 bg-emerald-50 text-emerald-700'
                                                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:text-slate-950',
                                                                )}
                                                            >
                                                                {notice.is_saved ? 'Lagret' : translations.frontend.save_button}
                                                            </button>
                                                        ) : null}
                                                        {isSavedMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => archiveNotice(notice)}
                                                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                                            >
                                                                Flytt til historikk
                                                            </button>
                                                        ) : null}
                                                        {isSavedMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => removeNotice(notice)}
                                                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                            >
                                                                Slett
                                                            </button>
                                                        ) : null}
                                                        {isHistoryMode ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => removeHistoryNotice(notice)}
                                                                className="inline-flex min-w-[132px] items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                                                            >
                                                                Slett
                                                            </button>
                                                        ) : null}
                                                        {notice.external_url ? (
                                                            <a
                                                                href={notice.external_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex min-w-[108px] items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                {noticeExternalLinkLabel(notice)}
                                                            </a>
                                                        ) : null}
                                                    </div>
                                                </div>

                                                        {isSavedOrHistoryMode && isDetailsExpanded ? (
                                                            <div className="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-600">
                                                                {isPrivateRequest ? (
                                                                    <div className="grid gap-2 sm:grid-cols-2">
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">Registrert av:</span>{' '}
                                                                            <span>{notice.saved_by_name || 'Ikke registrert'}</span>
                                                                        </div>
                                                                        {notice.saved_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Registrert:</span>{' '}
                                                                                <span>{formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' })}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">Oppdragsgiver:</span>{' '}
                                                                            <span>{notice.buyer_name || 'Ikke registrert'}</span>
                                                                        </div>
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">Frist:</span>{' '}
                                                                            <span>{notice.deadline ? formatDate(notice.deadline, locale) : 'Ikke registrert'}</span>
                                                                        </div>
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">Referanse:</span>{' '}
                                                                            <span>{notice.reference_number || 'Ikke registrert'}</span>
                                                                        </div>
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">Kontaktperson:</span>{' '}
                                                                            <span>{notice.contact_person_name || 'Ikke registrert'}</span>
                                                                        </div>
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">Kontakt e-post:</span>{' '}
                                                                            <span>{notice.contact_person_email || 'Ikke registrert'}</span>
                                                                        </div>
                                                                        <div className="sm:col-span-2">
                                                                            <span className="font-medium text-slate-700">Ekstern lenke:</span>{' '}
                                                                            {notice.external_url ? (
                                                                                <a
                                                                                    href={notice.external_url}
                                                                                    target="_blank"
                                                                                    rel="noreferrer"
                                                                                    className="font-medium text-violet-700 transition hover:text-violet-800"
                                                                                >
                                                                                    {noticeExternalLinkLabel(notice)}
                                                                                </a>
                                                                            ) : (
                                                                                <span>Ikke registrert</span>
                                                                            )}
                                                                        </div>
                                                                        {notice.notes ? (
                                                                            <div className="sm:col-span-2">
                                                                                <span className="font-medium text-slate-700">Notater:</span>{' '}
                                                                                <span className="whitespace-pre-line">{notice.notes}</span>
                                                                            </div>
                                                                        ) : null}
                                                                    </div>
                                                                ) : (
                                                                    <div className="grid gap-2 sm:grid-cols-2">
                                                                        <div>
                                                                            <span className="font-medium text-slate-700">Lagret av:</span>{' '}
                                                                            <span>{notice.saved_by_name || 'Ikke registrert'}</span>
                                                                        </div>
                                                                        {notice.saved_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Lagret:</span>{' '}
                                                                                <span>{formatDate(notice.saved_at, locale, { hour: '2-digit', minute: '2-digit' })}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.questions_rfi_deadline_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Spørsmål RFI:</span>{' '}
                                                                                <span>{formatDate(notice.questions_rfi_deadline_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.rfi_submission_deadline_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Innlevering RFI:</span>{' '}
                                                                                <span>{formatDate(notice.rfi_submission_deadline_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.questions_rfp_deadline_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Spørsmål RFP:</span>{' '}
                                                                                <span>{formatDate(notice.questions_rfp_deadline_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.rfp_submission_deadline_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Innlevering RFP:</span>{' '}
                                                                                <span>{formatDate(notice.rfp_submission_deadline_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {notice.award_date_at ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Tildeling:</span>{' '}
                                                                                <span>{formatDate(notice.award_date_at, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {isHistoryMode && notice.selected_supplier_name ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Valgt leverandør:</span>{' '}
                                                                                <span>{notice.selected_supplier_name}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {isHistoryMode && notice.contract_value_mnok !== null && notice.contract_value_mnok !== undefined ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">Avtaleverdi:</span>{' '}
                                                                                <span>{formatMnokValue(notice.contract_value_mnok, locale)}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {isHistoryMode && historyContractLabel ? (
                                                                            <div>
                                                                                <span className="font-medium text-slate-700">{historyContractLabel}</span>
                                                                            </div>
                                                                        ) : null}
                                                                        {needsHistorySelection ? (
                                                                            <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:col-span-2">
                                                                                Eksisterende historikkdata mangler strukturert oppfølgingsmodell. Velg anskaffelsestype og oppfølging før du lagrer på nytt.
                                                                            </div>
                                                                        ) : null}
                                                                        {isHistoryMode && notice.next_process_date_at ? (
                                                                            <div className="rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 sm:col-span-2">
                                                                                <div className="text-sm font-medium text-violet-900">Dato for neste oppfølging</div>
                                                                                <div className="mt-1 text-sm text-violet-700">{formatDate(notice.next_process_date_at, locale)}</div>
                                                                            </div>
                                                                        ) : isHistoryMode ? (
                                                                            <div className="sm:col-span-2">
                                                                                <span className="font-medium text-slate-700">Ingen planlagt oppfølging</span>
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
                                                                        <span className="text-sm font-medium text-slate-700">Frist spørsmål RFI</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.questions_rfi_deadline_at}
                                                                            onChange={(event) => deadlineForm.setData('questions_rfi_deadline_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.questions_rfi_deadline_at ? (
                                                                            <p className="text-sm text-rose-600">{deadlineForm.errors.questions_rfi_deadline_at}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-sm font-medium text-slate-700">Innlevering RFI</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.rfi_submission_deadline_at}
                                                                            onChange={(event) => deadlineForm.setData('rfi_submission_deadline_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.rfi_submission_deadline_at ? (
                                                                            <p className="text-sm text-rose-600">{deadlineForm.errors.rfi_submission_deadline_at}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-sm font-medium text-slate-700">Frist spørsmål RFP</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.questions_rfp_deadline_at}
                                                                            onChange={(event) => deadlineForm.setData('questions_rfp_deadline_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.questions_rfp_deadline_at ? (
                                                                            <p className="text-sm text-rose-600">{deadlineForm.errors.questions_rfp_deadline_at}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-sm font-medium text-slate-700">Innlevering RFP</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.rfp_submission_deadline_at}
                                                                            onChange={(event) => deadlineForm.setData('rfp_submission_deadline_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.rfp_submission_deadline_at ? (
                                                                            <p className="text-sm text-rose-600">{deadlineForm.errors.rfp_submission_deadline_at}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-sm font-medium text-slate-700">Tildelingsdato</span>
                                                                        <input
                                                                            type="date"
                                                                            value={deadlineForm.data.award_date_at}
                                                                            onChange={(event) => deadlineForm.setData('award_date_at', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {deadlineForm.errors.award_date_at ? (
                                                                            <p className="text-sm text-rose-600">{deadlineForm.errors.award_date_at}</p>
                                                                        ) : null}
                                                                    </label>
                                                                </div>

                                                                <div className="mt-4 flex flex-wrap gap-2.5">
                                                                    <button
                                                                        type="submit"
                                                                        disabled={deadlineForm.processing}
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
                                                                        <span className="text-sm font-medium text-slate-700">Valgt leverandør</span>
                                                                        <input
                                                                            type="text"
                                                                            value={historyForm.data.selected_supplier_name}
                                                                            onChange={(event) => historyForm.setData('selected_supplier_name', event.target.value)}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {historyForm.errors.selected_supplier_name ? (
                                                                            <p className="text-sm text-rose-600">{historyForm.errors.selected_supplier_name}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <label className="space-y-2">
                                                                        <span className="text-sm font-medium text-slate-700">Avtaleverdi (MNOK)</span>
                                                                        <input
                                                                            type="text"
                                                                            inputMode="decimal"
                                                                            value={formatNumberWithSpaces(historyForm.data.contract_value_mnok)}
                                                                            onChange={(event) => historyForm.setData('contract_value_mnok', parseNumberFromSpaces(event.target.value))}
                                                                            className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                        />
                                                                        {historyForm.errors.contract_value_mnok ? (
                                                                            <p className="text-sm text-rose-600">{historyForm.errors.contract_value_mnok}</p>
                                                                        ) : null}
                                                                    </label>

                                                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                                                        <div className="space-y-2">
                                                                            <span className="text-sm font-medium text-slate-700">Anskaffelsestype</span>
                                                                            <select
                                                                                value={historyForm.data.procurement_type}
                                                                                onChange={(event) => updateHistoryProcurementType(event.target.value)}
                                                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                            >
                                                                                <option value="">Velg anskaffelsestype</option>
                                                                                {historyProcurementTypeOptions.map((option) => (
                                                                                    <option key={option.value} value={option.value}>
                                                                                        {option.label}
                                                                                    </option>
                                                                                ))}
                                                                            </select>
                                                                            {historyForm.errors.procurement_type ? (
                                                                                <p className="text-sm text-rose-600">{historyForm.errors.procurement_type}</p>
                                                                            ) : null}
                                                                        </div>

                                                                        {shouldShowHistoryFollowUpField ? (
                                                                            <div className="space-y-2">
                                                                                <span className="text-sm font-medium text-slate-700">Oppfølging</span>
                                                                                <select
                                                                                    value={historyForm.data.follow_up_mode}
                                                                                    onChange={(event) => updateHistoryFollowUpMode(event.target.value)}
                                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                                >
                                                                                    {historyFollowUpOptions.map((option) => (
                                                                                        <option key={option.value} value={option.value}>
                                                                                            {option.label}
                                                                                        </option>
                                                                                    ))}
                                                                                </select>
                                                                                {historyForm.errors.follow_up_mode ? (
                                                                                    <p className="text-sm text-rose-600">{historyForm.errors.follow_up_mode}</p>
                                                                                ) : null}
                                                                            </div>
                                                                        ) : null}

                                                                        {isHistoryFormManualOffset ? (
                                                                            <div className="space-y-2">
                                                                                <span className="text-sm font-medium text-slate-700">Antall måneder fra i dag</span>
                                                                                <input
                                                                                    type="number"
                                                                                    inputMode="numeric"
                                                                                    min="1"
                                                                                    step="1"
                                                                                    value={historyForm.data.follow_up_offset_months}
                                                                                    onChange={(event) => historyForm.setData('follow_up_offset_months', event.target.value)}
                                                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                                />
                                                                                {historyForm.errors.follow_up_offset_months ? (
                                                                                    <p className="text-sm text-rose-600">{historyForm.errors.follow_up_offset_months}</p>
                                                                                ) : null}
                                                                            </div>
                                                                        ) : null}
                                                                    </div>

                                                                    {isHistoryFormRecurring ? (
                                                                        <label className="space-y-2">
                                                                            <span className="text-sm font-medium text-slate-700">Avtaleperiode (måneder)</span>
                                                                            <input
                                                                                type="number"
                                                                                inputMode="numeric"
                                                                                min="1"
                                                                                step="1"
                                                                                value={historyForm.data.contract_period_months}
                                                                                onChange={(event) => historyForm.setData('contract_period_months', event.target.value)}
                                                                                className="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-violet-300 focus:ring-4 focus:ring-violet-100"
                                                                            />
                                                                            <p className="text-xs text-slate-500">
                                                                                Valgfri kontraktsinformasjon. Påvirker ikke dato for neste oppfølging.
                                                                            </p>
                                                                            {historyForm.errors.contract_period_months ? (
                                                                                <p className="text-sm text-rose-600">{historyForm.errors.contract_period_months}</p>
                                                                            ) : null}
                                                                        </label>
                                                                    ) : null}

                                                                    {isHistoryFormManualOffset ? (
                                                                        <div className="rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-800">
                                                                            <div className="font-medium text-violet-900">Dato for neste oppfølging</div>
                                                                            <div className="mt-1 text-sm text-violet-700">
                                                                                {historyNextFollowUpPreview ? formatDate(historyNextFollowUpPreview, locale) : 'Angi antall måneder fra i dag for å beregne dato.'}
                                                                            </div>
                                                                            <p className="mt-1 text-xs text-violet-700">
                                                                                Angi antall måneder fra i dag. Datoen beregnes automatisk og brukes for senere varsling.
                                                                            </p>
                                                                        </div>
                                                                    ) : null}

                                                                    {shouldShowHistoryFollowUpField && historyForm.data.follow_up_mode === 'none' ? (
                                                                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
                                                                            <span className="font-medium text-slate-700">Ingen planlagt oppfølging</span>
                                                                        </div>
                                                                    ) : null}
                                                                </div>

                                                                <div className="mt-4 flex flex-wrap gap-2.5">
                                                                    <button
                                                                        type="submit"
                                                                        disabled={historyForm.processing}
                                                                        className="inline-flex items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                    >
                                                                        {historyForm.processing ? 'Lagrer...' : 'Lagre'}
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={cancelHistoryEditor}
                                                                        disabled={historyForm.processing}
                                                                        className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                                                    >
                                                                        Avbryt
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

                        <div className="flex flex-col gap-4 rounded-[20px] border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600 shadow-[0_8px_22px_rgba(15,23,42,0.04)] sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                {notices.meta.from && notices.meta.to
                                    ? `${formatInteger(notices.meta.from, locale)}–${formatInteger(notices.meta.to, locale)} av ${formatInteger(notices.meta.total, locale)}`
                                    : pluralize(notices.meta.total ?? 0, locale)}
                            </div>
                            <div className="flex gap-3">
                                <button
                                    type="button"
                                    disabled={!notices.meta.prev_page_url}
                                    onClick={() => notices.meta.prev_page_url && router.visit(notices.meta.prev_page_url)}
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {translations.common.previous}
                                </button>
                                <button
                                    type="button"
                                    disabled={!notices.meta.next_page_url}
                                    onClick={() => notices.meta.next_page_url && router.visit(notices.meta.next_page_url)}
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {translations.common.next}
                                </button>
                            </div>
                        </div>
                    </div>

                    <aside className="space-y-4 xl:sticky xl:top-7 xl:self-start">
                        <section id="saved-searches" className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]">
                            <div className="mb-5 flex items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <BookmarkIcon className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-xl font-semibold text-slate-950">{translations.frontend.saved_searches_title}</h2>
                                </div>
                                <button
                                    type="button"
                                    className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                                >
                                    {translations.frontend.see_all}
                                </button>
                            </div>
                            <div className="space-y-4">
                                {savedSearches.length === 0 ? (
                                    <div className="rounded-2xl bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                        Ingen lagrede søk er tilgjengelige ennå.
                                    </div>
                                ) : (
                                    savedSearches.map((item) => (
                                        <div key={item.id} className="space-y-2 border-b border-slate-100 pb-4 last:border-b-0 last:pb-0">
                                            <div className="text-base font-semibold text-slate-900">{item.name}</div>
                                            <div className="text-sm leading-6 text-slate-500">{item.summary}</div>
                                            <div className="flex flex-wrap gap-2">
                                                {item.department ? (
                                                    <span className="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                                        {item.department}
                                                    </span>
                                                ) : null}
                                                {item.frequency ? (
                                                    <span className="inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700">
                                                        {item.frequency}
                                                    </span>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </section>

                        <section id="alerts-monitoring" className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]">
                            <div className="mb-5 flex items-center gap-3">
                                <BellIcon className="h-5 w-5 text-slate-500" />
                                <h2 className="text-xl font-semibold text-slate-950">{translations.frontend.alerts_monitoring_title}</h2>
                            </div>

                            <div className="rounded-2xl border border-violet-200 bg-violet-50 px-4 py-4 text-sm leading-6 text-violet-900">
                                Her ser du status for overvåkning basert på dine aktive watch profiles.
                            </div>

                            <div className="mt-5 space-y-3 text-sm text-slate-600">
                                <div className="flex items-center gap-3">
                                    <span className="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                                    {monitoringHitsLabel}
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="h-2.5 w-2.5 rounded-full bg-slate-300" />
                                    {monitoringNextUpdateText}
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={canManageWatchProfiles ? () => router.get('/app/watch-profiles') : undefined}
                                disabled={!canManageWatchProfiles}
                                title={!canManageWatchProfiles ? 'Kun tilgjengelig for kundeadministrator.' : undefined}
                                className={classNames(
                                    'mt-5 inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition',
                                    canManageWatchProfiles
                                        ? 'hover:border-slate-300 hover:text-slate-950'
                                        : 'cursor-not-allowed opacity-60',
                                )}
                            >
                                {translations.frontend.alert_settings}
                            </button>
                        </section>

                        <section className="rounded-[20px] border border-slate-200 bg-white p-5 shadow-[0_8px_22px_rgba(15,23,42,0.04)]">
                            <div className="mb-5 flex items-center gap-3">
                                <ListIcon className="h-5 w-5 text-slate-500" />
                                <h2 className="text-xl font-semibold text-slate-950">{translations.frontend.worklist_title}</h2>
                            </div>

                            <div className="space-y-3">
                                {worklistSummary.map((item) => (
                                    <button
                                        key={item.key}
                                        type="button"
                                        onClick={() => visitMode(item.key)}
                                        className={classNames(
                                            'flex w-full items-center justify-between rounded-2xl px-4 py-3 text-left transition',
                                            mode === item.key ? 'bg-violet-50 ring-1 ring-violet-200' : 'bg-slate-50 hover:bg-slate-100',
                                        )}
                                    >
                                        <span className={classNames('text-sm', mode === item.key ? 'text-violet-800' : 'text-slate-700')}>{item.label}</span>
                                        <span
                                            className={classNames(
                                                'inline-flex h-7 min-w-[28px] items-center justify-center rounded-full px-2 text-xs font-semibold',
                                                mode === item.key ? 'bg-violet-200 text-violet-800' : 'bg-slate-200 text-slate-700',
                                            )}
                                        >
                                            {item.count}
                                        </span>
                                    </button>
                                ))}
                            </div>

                            <button
                                type="button"
                                onClick={() => visitMode('live')}
                                className="mt-5 inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                            >
                                {isLiveMode ? 'Live søk er aktivt' : 'Til live søk i Doffin'}
                            </button>
                        </section>
                    </aside>
                </div>
            </div>
        </CustomerAppLayout>
    );
}
