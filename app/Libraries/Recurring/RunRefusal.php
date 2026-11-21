<?php

namespace App\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use RuntimeException;
use Throwable;

/**
 * Why the document of one date was not made. The refusal is named by where it came from (the books,
 * the posting, the branch of the employee, the sale itself) and by the code of that refusal, so the
 * history of a schedule can say it in words long after the run that met it.
 */
final class RunRefusal extends RuntimeException
{
    public const SOURCE_ACCOUNTING = 'accounting';
    public const SOURCE_POSTING    = 'posting';
    public const SOURCE_BRANCH     = 'branch';
    public const SOURCE_SALE       = 'sale';
    public const SOURCE_EINVOICE   = 'einvoice';
    public const CONTEXT_MAX       = 500;

    public ?string $dueDate = null;
    public int $occurrence  = 0;

    /**
     * @param array<string, scalar> $context
     */
    public function __construct(public readonly string $source, public readonly string $error, public readonly array $context = [])
    {
        parent::__construct($source . ':' . $error);
    }

    public function forDate(string $dueDate, int $occurrence): self
    {
        $this->dueDate    = $dueDate;
        $this->occurrence = $occurrence;

        return $this;
    }

    public static function from(Throwable $failure): self
    {
        return match (true) {
            $failure instanceof AccountingException => new self(self::SOURCE_ACCOUNTING, $failure->error->value, self::scalars($failure->context)),
            $failure instanceof PostingException    => new self(self::SOURCE_POSTING, $failure->error->value, self::scalars($failure->context)),
            $failure instanceof BranchException     => new self(self::SOURCE_BRANCH, $failure->error->value, self::scalars($failure->context)),
            $failure instanceof EInvoiceException   => new self(self::SOURCE_EINVOICE, $failure->error->value, self::scalars($failure->context)),
            default                                 => new self(self::SOURCE_SALE, 'save_failed'),
        };
    }

    public static function sale(string $code): self
    {
        return new self(self::SOURCE_SALE, $code);
    }

    public function contextJson(): ?string
    {
        if ($this->context === []) {
            return null;
        }

        return mb_substr(json_encode($this->context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0, self::CONTEXT_MAX);
    }

    public function words(): string
    {
        return self::wordsOf($this->source, $this->error, $this->contextJson());
    }

    public static function wordsOf(?string $source, ?string $code, ?string $contextJson): string
    {
        $context = $contextJson === null ? [] : json_decode($contextJson, true);
        $context = is_array($context) ? $context : [];

        $failure = match ($source) {
            self::SOURCE_ACCOUNTING => ($error = AccountingError::tryFrom((string) $code)) === null ? null : new AccountingException($error, '', $context),
            self::SOURCE_POSTING    => ($error = PostingError::tryFrom((string) $code)) === null ? null : new PostingException($error, '', $context),
            self::SOURCE_BRANCH     => ($error = BranchError::tryFrom((string) $code)) === null ? null : new BranchException($error, '', $context),
            default                 => null,
        };

        if ($failure !== null) {
            return AccountingMessage::of($failure);
        }

        if ($source === self::SOURCE_EINVOICE && ($error = EInvoiceError::tryFrom((string) $code)) !== null) {
            return (new EInvoiceException($error, '', $context))->describe();
        }

        $key  = 'Recurring.refusal_' . $code;
        $line = lang($key);

        return $line === $key ? (string) $code : $line;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, scalar>
     */
    private static function scalars(array $context): array
    {
        return array_filter($context, static fn (mixed $value): bool => is_scalar($value));
    }
}
