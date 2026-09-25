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

    /**
     * Remove one message from this person's bell.
     *
     * Scoped exactly as markRead() is, and for the same reason: the id in the URL is the caller's
     * claim, not a fact. A row belonging to another user, or to another customer, is not refused —
     * it is not found, which is the honest answer to "does this exist for you".
     *
     * A message is not the work it announced. Nothing here reads a Wiki assignment, a QA assignment
     * or a case, so an outstanding task stays outstanding and stays on the Info Center list.
     */
    public function destroy(Request $request, UserNotification $userNotification): JsonResponse
    {
        [$user, $customerId] = $this->frontendContext($request);

        abort_unless(
            (int) $userNotification->user_id === (int) $user->id
            && (int) $userNotification->customer_id === (int) $customerId,
            404,
        );

        $this->notificationService->delete($userNotification);

        return response()->json([
            'notifications' => $this->notificationService->panelPayload($user),
        ]);
    }

    /**
     * Clear everything this person has not read yet.
     *
     * Read messages stay. Deleting nothing is a success: an already-empty bell is the state the
     * caller asked for, and reporting it as a failure would only invite a retry that does the same.
     */
    public function destroyUnread(Request $request): JsonResponse
    {
        [$user] = $this->frontendContext($request);

        $deleted = $this->notificationService->deleteAllUnread($user);

        return response()->json([
            'deleted' => $deleted,
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
