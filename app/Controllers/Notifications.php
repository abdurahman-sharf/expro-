<?php

namespace App\Controllers;

use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\Notifications as NotificationList;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use DateTimeImmutable;
use IntlDateFormatter;

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
            'days'       => $this->byDay($found['rows']),
            'from'       => $found['rows'] === [] ? 0 : ($page - 1) * self::PAGE_SIZE + 1,
            'to'         => ($page - 1) * self::PAGE_SIZE + count($found['rows']),
            'total'      => $found['total'],
            'unread'     => $found['unread'],
            'page'       => $page,
            'pages'      => max(1, (int) ceil($found['total'] / self::PAGE_SIZE)),
            'unreadOnly' => $unreadOnly,
            'enabled'    => (new IntegrationOptions())->notificationsEnabled(),
        ]);
    }

    /**
     * The notifications of the page under the day they were raised on: today, yesterday, then the name of the day,
     * each with its date and every notification with its date and time, in the formats the company chose.
     *
     * @param list<object> $rows Newest first.
     *
     * @return list<array{label: string, date: string, rows: list<object>}>
     */
    private function byDay(array $rows): array
    {
        $settings = config(OSPOS::class)->settings;
        $today    = new DateTimeImmutable('today');
        $weekday  = new IntlDateFormatter(current_language_code(), IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'EEEE');
        $days     = [];

        foreach ($rows as $row) {
            $raised = new DateTimeImmutable((string) $row->created_at);
            $key    = $raised->format('Y-m-d');

            if (! isset($days[$key])) {
                $age = (int) $today->diff($raised->setTime(0, 0))->format('%r%a');

                $days[$key] = [
                    'label' => match ($age) {
                        0       => lang('Integrations.notifications_today'),
                        -1      => lang('Integrations.notifications_yesterday'),
                        default => (string) $weekday->format($raised),
                    },
                    'date' => $raised->format($settings['dateformat']),
                    'rows' => [],
                ];
            }

            $row->raised_date = $raised->format($settings['dateformat']);
            $row->raised_time = $raised->format($settings['timeformat']);
            $row->raised_iso  = $raised->format('Y-m-d\TH:i:s');

            $days[$key]['rows'][] = $row;
        }

        return array_values($days);
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
