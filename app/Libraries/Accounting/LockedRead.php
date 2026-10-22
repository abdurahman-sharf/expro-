<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;

/**
 * A read that locks rows, such as FOR UPDATE. When the lock cannot be had, because another
 * request holds it too long or the server chose this one to give up, a connection without
 * debugging returns false instead of throwing. That must stop the work and not be mistaken
 * for an empty answer.
 */
final class LockedRead
{
    /**
     * @param list<int|string> $params
     *
     * @throws AccountingException
     */
    public static function row(BaseConnection $db, string $sql, array $params = []): ?object
    {
        $result = $db->query($sql, $params);

        if ($result === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The rows could not be locked.');
        }

        return $result->getRow();
    }

    /**
     * @param list<int|string> $params
     *
     * @return list<object>
     *
     * @throws AccountingException
     */
    public static function rows(BaseConnection $db, string $sql, array $params = []): array
    {
        $result = $db->query($sql, $params);

        if ($result === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The rows could not be locked.');
        }

        return $result->getResult();
    }
}
