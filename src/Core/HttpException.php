<?php
declare(strict_types=1);

namespace Codify\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /** @var int */
    public $status;
    /** @var array */
    public $errors;
    /** @var string|null */
    public $errorCode;

    public function __construct(int $status, string $message, array $errors = [], ?string $errorCode = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errors = $errors;
        $this->errorCode = $errorCode;
    }
}
