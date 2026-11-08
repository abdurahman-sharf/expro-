<?php

namespace App\Libraries\MasterData;

enum CustomerStatus: string
{
    case RequiresFollowUp = 'requires_follow_up';
    case Interested       = 'interested';
    case Served           = 'served';
    case UnderReview      = 'under_review';
    case Archived         = 'archived';

    public function label(): string
    {
        return lang('Customers.status_' . $this->value);
    }

    /**
     * @return array<string, string> The label of each status, by its code.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
