<?php

namespace App\Libraries\Accounting\Readiness;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Whether the clock of the database and the clock of the application name the same day. The invoice and
 * the purchase take the day from the application (PHP date(), in the zone of the shop), and so does the
 * day of a supplier payment and of a posting. The day of a payment on a sale takes the day
 * the database stamps by itself (sales_payments.payment_time defaults to the database clock), and the
 * journal of that payment is dated with it: a database a few hours ahead of or behind the shop puts the
 * payment in the books on another day than the invoice it pays, in another period and under another
 * rate for a few hours of every day. It reads two variables and counts payments, and changes nothing.
 */
final class ClockChecks extends CheckGroup
{
    private const LISTED = 20;

    public function run(): array
    {
        return [
            $this->check('clock_zones', 'W93-CLOCK', 'The clock of the database and the clock of the application name the same day', ['app_config', 'sales.sale_time', 'sales_payments.payment_time'], fn (): CheckResult => $this->zones()),
        ];
    }

    private function zones(): CheckResult
    {
        $database = $this->db->query('SELECT @@session.time_zone AS session_zone, @@global.time_zone AS global_zone, @@system_time_zone AS system_zone, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds')->getRow();

        if ($database === null || $database->offset_seconds === null) {
            return CheckResult::skipped('Not checked: the database did not report the time zone of its clock.');
        }

        $now             = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $processZone     = date_default_timezone_get();
        $configured      = $this->configuredZone();
        $shopZone        = $configured ?? $processZone;
        $databaseOffset  = (int) $database->offset_seconds;
        $shopOffset      = (new DateTimeZone($shopZone))->getOffset($now);
        $processOffset   = (new DateTimeZone($processZone))->getOffset($now);
        $difference      = $databaseOffset - $shopOffset;
        $tomorrow        = $now->setTimezone(new DateTimeZone($shopZone))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $future          = $this->paymentsAfter($tomorrow);
        $beforeTheirSale = $this->paymentsBeforeTheirSale();

        $data = [
            'database_zone'           => (string) $database->session_zone,
            'database_global_zone'    => (string) $database->global_zone,
            'database_system_zone'    => (string) $database->system_zone,
            'database_offset'         => $this->offset($databaseOffset),
            'shop_zone'               => $shopZone,
            'shop_zone_from_settings' => $configured !== null,
            'shop_offset'             => $this->offset($shopOffset),
            'process_zone'            => $processZone,
            'process_offset'          => $this->offset($processOffset),
            'difference_minutes'      => intdiv($difference, 60),
            'payments_dated_ahead'    => $future['count'],
            'payments_before_sale'    => $beforeTheirSale['count'],
        ];

        $findings = [];

        if ($difference !== 0) {
            $findings[] = sprintf(
                'The database stamps times in %s (session time_zone %s, system zone %s) and the shop works in %s (%s%s): they name different days for %s of every day.',
                $data['database_offset'],
                $data['database_zone'],
                $data['database_system_zone'],
                $shopZone,
                $data['shop_offset'],
                $configured === null ? ', the zone of the application because the locale settings name none' : ', from the locale settings',
                $this->hours(abs($difference)),
            );
            $findings[] = 'Where the dates come from: the date of an invoice, of a purchase and of a supplier payment, and every date the application computes, come from the application clock; the day of a payment on a sale (and so the date of its journal) comes from the database clock, because sales_payments.payment_time is stamped by the database.';
            $findings[] = 'To align them: set the default time zone of the database server to the zone of the shop (default-time-zone in the server configuration, or SET GLOBAL time_zone), or set the zone of the shop in the locale settings to the one of the database; then run this report again.';
        }

        if ($processOffset !== $shopOffset) {
            $findings[] = sprintf('The command line runs in %s (%s) while the shop works in %s (%s): accounting:generate_recurring and accounting:depreciate apply the zone of the shop and every other php spark command dates what it writes in the zone of the process; set app.appTimezone in the .env file to the zone of the shop.', $processZone, $data['process_offset'], $shopZone, $data['shop_offset']);
        }

        foreach ($future['listed'] as $payment) {
            $findings[] = sprintf('payment #%d on sale #%d is stamped %s, a day that has not come in the zone of the shop', $payment->payment_id, $payment->sale_id, $payment->payment_time);
        }

        foreach ($beforeTheirSale['listed'] as $payment) {
            $findings[] = sprintf('payment #%d is stamped %s, on a day before sale #%d (%s)', $payment->payment_id, $payment->payment_time, $payment->sale_id, substr((string) $payment->sale_time, 0, 10));
        }

        if ($difference !== 0 && $future['count'] > 0) {
            return CheckResult::fail(
                $future['count'] . ' payment(s) on sales are stamped on a day that has not come in the zone of the shop, and their journals carry that day: the clock of the database is ahead of the shop.',
                array_slice($findings, 0, self::LISTED),
                $data,
            );
        }

        if ($findings !== []) {
            $summary = match (true) {
                $difference !== 0                                     => sprintf('The database clock and the shop clock differ by %s: they name different days for part of every day.', $this->hours(abs($difference))),
                $future['count'] > 0 || $beforeTheirSale['count'] > 0 => 'The clocks agree now, but payments are stamped on days that do not fit their sale or the calendar of the shop.',
                default                                               => sprintf('The command line runs in %s while the shop works in %s.', $processZone, $shopZone),
            };

            return CheckResult::review($summary, array_slice($findings, 0, self::LISTED), $data);
        }

        return CheckResult::pass(sprintf('The database and the shop both work in %s (%s): they name the same day.', $shopZone, $data['shop_offset']), [], $data);
    }

    private function configuredZone(): ?string
    {
        $row  = $this->db->table('app_config')->select('value')->where('key', 'timezone')->get()->getRow();
        $zone = trim((string) ($row->value ?? ''));

        if ($zone === '') {
            return null;
        }

        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return null;
        }

        return $zone;
    }

    /**
     * @return array{count: int, listed: list<object>}
     */
    private function paymentsAfter(string $firstMoment): array
    {
        $count = (int) $this->db->table('sales_payments')->where('payment_time >=', $firstMoment)->countAllResults();

        return ['count' => $count, 'listed' => $count === 0 ? [] : $this->db->table('sales_payments')
            ->select('payment_id, sale_id, payment_time')
            ->where('payment_time >=', $firstMoment)
            ->orderBy('payment_id', 'ASC')
            ->limit(self::LISTED)
            ->get()
            ->getResult()];
    }

    /**
     * @return array{count: int, listed: list<object>}
     */
    private function paymentsBeforeTheirSale(): array
    {
        $count = (int) $this->db->table('sales_payments p')
            ->join('sales s', 's.sale_id = p.sale_id')
            ->where('DATE(p.payment_time) < DATE(s.sale_time)', null, false)
            ->countAllResults();

        return ['count' => $count, 'listed' => $count === 0 ? [] : $this->db->table('sales_payments p')
            ->select('p.payment_id, p.sale_id, p.payment_time, s.sale_time')
            ->join('sales s', 's.sale_id = p.sale_id')
            ->where('DATE(p.payment_time) < DATE(s.sale_time)', null, false)
            ->orderBy('p.payment_id', 'ASC')
            ->limit(self::LISTED)
            ->get()
            ->getResult()];
    }

    private function offset(int $seconds): string
    {
        return sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
    }

    private function hours(int $seconds): string
    {
        $hours = $seconds / 3600;

        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.') . ' hour(s)';
    }
}
