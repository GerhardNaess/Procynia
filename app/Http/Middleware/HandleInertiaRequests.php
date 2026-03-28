<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly CustomerContext $customerContext,
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
        $department = $user instanceof User && $user->relationLoaded('department')
            ? $user->department
            : ($user instanceof User ? $user->department()->first() : null);

        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'can_manage_customer_users' => $this->customerContext->isCustomerAdmin($user),
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
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
                'userCreated' => fn (): ?array => $request->session()->get('userCreated'),
            ],
            'translations' => [
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
