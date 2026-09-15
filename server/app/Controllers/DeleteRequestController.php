<?php

class DeleteRequestController extends Controller
{
    public function store(): void
    {
        $user = $this->requireAuth();
        Csrf::verify();
        $entityType = trim((string)Request::input('entity_type'));
        $ids = Request::input('ids', []);
        $ids = is_array($ids) ? $ids : [$ids];
        $assigneeId = (int)Request::input('assigned_to_user_id');
        $note = trim((string)Request::input('request_note'));
        $redirect = $this->safeRedirect((string)Request::input('redirect_to'));

        try {
            $requestId = DeleteRequestService::create($user, $entityType, $ids, $assigneeId, $note);
            Audit::log('created_delete_request', 'delete_request', $requestId, [
                'entity_type' => $entityType,
                'entity_ids' => array_values(array_map('intval', $ids)),
                'assigned_to_user_id' => $assigneeId,
            ]);
            $this->flash('success', 'Delete request assigned. The selected approver can now approve or reject it from Tasks.');
        } catch (Throwable $exception) {
            $this->flash('danger', $exception->getMessage());
        }
        Response::redirect($redirect);
    }

    public function approve(): void
    {
        $this->decide('approved');
    }

    public function reject(): void
    {
        $this->decide('rejected');
    }

    private function decide(string $decision): void
    {
        $user = $this->requireAuth();
        Csrf::verify();
        try {
            DeleteRequestService::decide($user, (int)Request::input('id'), $decision, (string)Request::input('decision_note'));
            $this->flash('success', $decision === 'approved' ? 'Delete request approved and the selected item was deleted.' : 'Delete request rejected. No item was deleted.');
        } catch (Throwable $exception) {
            $this->flash('danger', $exception->getMessage());
        }
        Response::redirect('/task');
    }

    private function safeRedirect(string $redirect): string
    {
        $allowed = ['/device', '/employees', '/offices', '/unassigned-devices', '/unassigned-devices?status=online', '/unassigned-devices?status=network'];
        return in_array($redirect, $allowed, true) ? $redirect : '/task';
    }
}
