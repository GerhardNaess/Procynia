<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\UserNotificationService;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly UserNotificationService $notificationService,
    ) {}

    /**
     * The current panel, for the bell to re-read without a page load.
     *
     * The same payload the layout is given on every Inertia visit, so a poll and a navigation put
     * the bell in exactly the same state. Read-only: polling must never mark anything as read, or
     * simply leaving a tab open would quietly empty the unread count.
     */
    public function index(Request $request): JsonResponse
    {
        [$user] = $this->frontendContext($request);

        return response()->json([
            'notifications' => $this->notificationService->panelPayload($user),
        ]);
    }

    public function markRead(Request $request, UserNotification $userNotification): JsonResponse
    {
        [$user, $customerId] = $this->frontendContext($request);

        abort_unless(
            (int) $userNotification->user_id === (int) $user->id
            && (int) $userNotification->customer_id === (int) $customerId,
            404,
        );

        $this->notificationService->markAsRead($userNotification);

        return response()->json([
            'notifications' => $this->notificationService->panelPayload($user),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        [$user] = $this->frontendContext($request);

        $this->notificationService->markAllAsRead($user);

        return response()->json([
            'notifications' => $this->notificationService->panelPayload($user),
        ]);
    }

    private function frontendContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $user->canAccessCustomerFrontend()
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }
}
