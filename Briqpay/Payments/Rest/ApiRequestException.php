<?php
declare(strict_types=1);

namespace Briqpay\Payments\Rest;

/**
 * Briqpay answered a request with an error status.
 */
class ApiRequestException extends \Exception
{
    private int $statusCode;

    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The status code Briqpay refused the request with, somewhere in the chain of wrapped
     * exceptions, or null when it did not answer.
     */
    public static function statusIn(\Throwable $error): ?int
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e->statusCode;
            }
        }

        return null;
    }

    /**
     * Whether Briqpay refused the request itself (a 4xx: unknown session, wrong
     * credentials), somewhere in the chain of wrapped exceptions, rather than failing to
     * answer.
     */
    public static function refusedIn(\Throwable $error): bool
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self && $e->statusCode >= 400 && $e->statusCode < 500) {
                return true;
            }
        }

        return false;
    }
}
