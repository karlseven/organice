<?php
declare(strict_types=1);

namespace Core;

/**
 * Thrown anywhere below the front controller to end the request with a status.
 *
 * 404 is deliberately used for "you may not see this" as well as "not there":
 * a 403 on a private space's URL confirms the space exists, which is exactly
 * what someone probing for it wants to learn.
 */
final class HttpError extends \RuntimeException
{
    /* Written once in the constructor and never again. This was a promoted
       `readonly` parameter, which reads better but is PHP 8.1 syntax — and this
       app supports 7.4 upward, where it is a parse error that takes down every
       request. See docs/DEPLOYMENT.md. */
    public $status;

    public function __construct(int $status, string $message = '')
    {
        $this->status = $status;
        parent::__construct($message);
    }
}
