<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserNotificationService;
use App\Support\CustomerContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly UserNotificationService $notificationService,
    ) {
    }

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $customer = $this->customerContext->currentCustomer($user instanceof User ? $user : null);
        $department = $user instanceof User && $user->relationLoaded('primaryDepartment')
            ? $user->primaryDepartment
            : ($user instanceof User ? $user->primaryDepartment()->first() : null);

        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'bid_role' => $user->resolvedBidRole(),
                    'bid_role_label' => $user->bid_role_label,
                    'bid_manager_scope' => $user->resolvedBidManagerScope(),
                    'bid_manager_scope_label' => $user->bid_manager_scope_label,
                    'primary_affiliation_scope' => $user->resolvedPrimaryAffiliationScope(),
                    'primary_affiliation_scope_label' => $user->primary_affiliation_scope_label,
                    'is_system_owner' => $user->isSystemOwner(),
                    'is_bid_manager' => $user->isBidManager(),
                    'can_manage_customer_users' => $this->customerContext->canManageCustomerUsers($user),
                    'can_manage_customer_departments' => $this->customerContext->canCreateCustomerDepartments($user),
                    'can_manage_watch_profiles' => $user->canAccessCustomerFrontend(),
                    'can_manage_department_watch_profiles' => $this->customerContext->isCustomerAdmin($user) || $this->customerContext->hasDepartmentMembership($user),
                    'customer' => $customer ? [
                        'id' => $customer->id,
                        'name' => $customer->name,
                    ] : null,
                    'department' => $department ? [
                        'id' => $department->id,
                        'name' => $department->name,
                    ] : null,
                ] : null,
            ],
            'notifications' => $user instanceof User
                ? $this->notificationService->panelPayload($user)
                : [
                    'unread_count' => 0,
                    'limit' => UserNotificationService::DEFAULT_LIMIT,
                    'mark_all_read_url' => route('app.notifications.read-all'),
                    'items' => [],
                ],
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
                'userCreated' => fn (): ?array => $request->session()->get('userCreated'),
                'retrievalTest' => fn (): ?array => $request->session()->get('retrievalTest'),
            ],
            'translations' => [
                'ai' => [
                    'no_files_selected' => __('procynia.ai.no_files_selected'),
                    'answer_draft_not_generated' => __('procynia.ai.answer_draft_not_generated'),
                    'answer_draft_cannot_be_empty' => __('procynia.ai.answer_draft_cannot_be_empty'),
                    'answer_draft_cannot_generate' => __('procynia.ai.answer_draft_cannot_generate'),
                    'answer_draft_cannot_save' => __('procynia.ai.answer_draft_cannot_save'),
                    'generating' => __('procynia.ai.generating'),
                    'regenerate' => __('procynia.ai.regenerate'),
                    'normal_reader' => __('procynia.ai.normal_reader'),
                    'larger_reader' => __('procynia.ai.larger_reader'),
                    'generating_answer_draft' => __('procynia.ai.generating_answer_draft'),
                    'answer_draft_for_requirement' => __('procynia.ai.answer_draft_for_requirement'),
                    'answer_draft_placeholder' => __('procynia.ai.answer_draft_placeholder'),
                    'selected_sources' => __('procynia.ai.selected_sources'),
                    'not_assigned' => __('procynia.ai.not_assigned'),
                    'create_answer' => __('procynia.ai.create_answer'),
                    'open_prompt_for_requirement' => __('procynia.ai.open_prompt_for_requirement'),
                    'generate_draft_for_requirement' => __('procynia.ai.generate_draft_for_requirement'),
                    'generated' => __('procynia.ai.generated'),
                    'unsaved_changes' => __('procynia.ai.unsaved_changes'),
                    'example_prompt_placeholder' => __('procynia.ai.example_prompt_placeholder'),
                    'knowledge_grounding_green' => __('procynia.ai.knowledge_grounding_green'),
                    'knowledge_grounding_amber' => __('procynia.ai.knowledge_grounding_amber'),
                    'knowledge_grounding_red' => __('procynia.ai.knowledge_grounding_red'),
                    'work_status_not_started' => __('procynia.ai.work_status_not_started'),
                    'work_status_in_progress' => __('procynia.ai.work_status_in_progress'),
                    'work_status_done' => __('procynia.ai.work_status_done'),
                ],
                'common' => [
                    'back' => __('procynia.frontend.back'),
                    'customer' => __('procynia.common.customer'),
                    'deadline' => __('procynia.notice.deadline'),
                    'download' => __('procynia.frontend.download'),
                    'loading' => __('procynia.frontend.loading'),
                    'next' => __('procynia.frontend.next'),
                    'not_available' => __('procynia.common.not_available'),
                    'logout' => __('procynia.frontend.logout'),
                    'none' => __('procynia.common.none'),
                    'notice' => __('procynia.notice.resource'),
                    'notices' => __('procynia.frontend.notices_nav'),
                    'open' => __('procynia.frontend.open'),
                    'previous' => __('procynia.frontend.previous'),
                    'published' => __('procynia.notice.publication_date'),
                    'search' => __('procynia.frontend.search'),
                    'status' => __('procynia.notice.status'),
                ],
                'frontend' => [
                    'all' => __('procynia.frontend.all'),
                    'alert_settings' => __('procynia.frontend.alert_settings'),
                    'alert_summary' => __('procynia.frontend.alert_summary'),
                    'alerts_monitoring_title' => __('procynia.frontend.alerts_monitoring_title'),
                    'alerts_nav' => __('procynia.frontend.alerts_nav'),
                    'apply_filters' => __('procynia.frontend.apply_filters'),
                    'buyer' => __('procynia.frontend.buyer'),
                    'clear_filters' => __('procynia.frontend.clear_filters'),
                    'customer_area' => __('procynia.frontend.customer_area'),
                    'customer_footer' => __('procynia.frontend.customer_footer'),
                    'customer_safe_reasoning' => __('procynia.frontend.customer_safe_reasoning'),
                    'deadline_expired' => __('procynia.frontend.deadline_expired'),
                    'deadline_open' => __('procynia.frontend.deadline_open'),
                    'department' => __('procynia.common.department'),
                    'document_count' => __('procynia.frontend.document_count'),
                    'documents' => __('procynia.frontend.documents'),
                    'download' => __('procynia.frontend.download'),
                    'download_all' => __('procynia.frontend.download_all'),
                    'download_all_failed' => __('procynia.frontend.download_all_failed'),
                    'empty' => __('procynia.frontend.empty_state'),
                    'empty_list_title' => __('procynia.frontend.empty_list_title'),
                    'file_size' => __('procynia.frontend.file_size'),
                    'file_type' => __('procynia.frontend.file_type'),
                    'filters_title' => __('procynia.frontend.filters_title'),
                    'go_to_worklist' => __('procynia.frontend.go_to_worklist'),
                    'keyword' => __('procynia.frontend.keyword'),
                    'matched_for_customer' => __('procynia.frontend.matched_for_customer'),
                    'new_hits_last_day' => __('procynia.frontend.new_hits_last_day'),
                    'no_department_context' => __('procynia.frontend.no_department_context'),
                    'no_documents' => __('procynia.frontend.no_documents'),
                    'notice_list_title' => __('procynia.frontend.notice_list_title'),
                    'notice_detail_title' => __('procynia.frontend.notice_detail_title'),
                    'notice_source_attention' => __('procynia.frontend.notice_source_attention'),
                    'notice_reference' => __('procynia.frontend.notice_reference'),
                    'open_button' => __('procynia.frontend.open_button'),
                    'open_notice' => __('procynia.frontend.open_notice'),
                    'organization_name' => __('procynia.frontend.organization_name'),
                    'overview_nav' => __('procynia.frontend.overview_nav'),
                    'infosenter_nav' => __('procynia.frontend.infosenter_nav'),
                    'published' => __('procynia.notice.publication_date'),
                    'procurements_nav' => __('procynia.frontend.procurements_nav'),
                    'procurements_subtitle' => __('procynia.frontend.procurements_subtitle'),
                    'publish_date' => __('procynia.frontend.publish_date'),
                    'reason_customer_match' => __('procynia.frontend.reason_customer_match'),
                    'reason_watch_profile' => __('procynia.frontend.reason_watch_profile'),
                    'relevance' => __('procynia.notice.relevance_level'),
                    'relevance_score' => __('procynia.frontend.relevance_score'),
                    'relevant_for_departments' => __('procynia.frontend.relevant_for_departments'),
                    'save_button' => __('procynia.frontend.save_button'),
                    'saved_searches_nav' => __('procynia.frontend.saved_searches_nav'),
                    'saved_searches_title' => __('procynia.frontend.saved_searches_title'),
                    'search_button' => __('procynia.frontend.search_button'),
                    'search_placeholder' => __('procynia.frontend.search_placeholder'),
                    'see_all' => __('procynia.frontend.see_all'),
                    'source_of_truth' => __('procynia.frontend.source_of_truth'),
                    'status' => __('procynia.notice.status'),
                    'sort_by' => __('procynia.frontend.sort_by'),
                    'support_mode_customer' => __('procynia.frontend.support_mode_customer'),
                    'support_mode_label' => __('procynia.frontend.support_mode_label'),
                    'tenant_safe' => __('procynia.frontend.tenant_safe_notice'),
                    'tenant_safe_notice' => __('procynia.frontend.tenant_safe_notice'),
                    'worklist_nav' => __('procynia.frontend.worklist_nav'),
                    'worklist_title' => __('procynia.frontend.worklist_title'),
                    'watch_profile' => __('procynia.common.watch_profile'),
                    'next_update' => __('procynia.frontend.next_update'),
                    'notice_status_all' => __('procynia.frontend.notice_status_all'),
                    'notice_status_active' => __('procynia.frontend.notice_status_active'),
                    'notice_status_expired' => __('procynia.frontend.notice_status_expired'),
                    'notice_status_awarded' => __('procynia.frontend.notice_status_awarded'),
                    'notice_status_cancelled' => __('procynia.frontend.notice_status_cancelled'),
                    'relevance_all' => __('procynia.frontend.relevance_all'),
                    'relevance_high' => __('procynia.frontend.relevance_high'),
                    'relevance_medium' => __('procynia.frontend.relevance_medium'),
                    'relevance_low' => __('procynia.frontend.relevance_low'),
                    'bid_status_all' => __('procynia.frontend.bid_status_all'),
                    'bid_status_discovered' => __('procynia.frontend.bid_status_discovered'),
                    'bid_status_qualifying' => __('procynia.frontend.bid_status_qualifying'),
                    'bid_status_go_no_go' => __('procynia.frontend.bid_status_go_no_go'),
                    'bid_status_in_progress' => __('procynia.frontend.bid_status_in_progress'),
                    'bid_status_submitted' => __('procynia.frontend.bid_status_submitted'),
                    'bid_status_negotiation' => __('procynia.frontend.bid_status_negotiation'),
                    'bid_status_won' => __('procynia.frontend.bid_status_won'),
                    'bid_status_lost' => __('procynia.frontend.bid_status_lost'),
                    'bid_status_no_go' => __('procynia.frontend.bid_status_no_go'),
                    'bid_status_withdrawn' => __('procynia.frontend.bid_status_withdrawn'),
                    'bid_status_archived' => __('procynia.frontend.bid_status_archived'),
                ],
                'auth' => [
                    'email' => __('procynia.user.email'),
                    'password' => __('procynia.user.password'),
                    'remember' => __('procynia.frontend.remember_me'),
                    'sign_in' => __('procynia.frontend.sign_in'),
                    'title' => __('procynia.frontend.sign_in_title'),
                    'subtitle' => __('procynia.frontend.sign_in_subtitle'),
                ],
            ],
        ]);
    }
}
