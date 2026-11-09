<?php

namespace App\Controllers;

use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\Notifications as NotificationList;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * What the in-app notification rules have found, for the branches the employee works in. It needs no
 * configuration grant: it is a list the owner gives to the employees who should see it.
 */
class Notifications extends IntegrationController
{
    private const PAGE_SIZE = 50;

    public function __construct()
    {
        parent::__construct(IntegrationAccess::NOTIFICATIONS_MODULE, false);
    }

    public function getIndex(): string
    {
        $unreadOnly = $this->requestText('show') === 'unread';
        $page       = max(1, (int) $this->request->getGet('page'));
        $found      = (new NotificationList(null, $this->branchGuard()))->visible($this->employeeId(), $unreadOnly, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return view('notifications/manage', [
            'rows'       => $found['rows'],
            'total'      => $found['total'],
            'unread'     => $found['unread'],
            'page'       => $page,
            'pages'      => max(1, (int) ceil($found['total'] / self::PAGE_SIZE)),
            'unreadOnly' => $unreadOnly,
            'enabled'    => (new IntegrationOptions())->notificationsEnabled(),
        ]);
    }

    public function postRead(int $notificationId): ResponseInterface
    {
        return $this->attempt(function () use ($notificationId): array {
            (new NotificationList(null, $this->branchGuard()))->markRead($notificationId, $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.notification_read'), 'id' => $notificationId];
        });
    }

    public function postReadAll(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $marked = (new NotificationList(null, $this->branchGuard()))->markAllRead($this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.notifications_read', [(string) $marked]), 'id' => $marked];
        });
    }
}
