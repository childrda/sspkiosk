<?php

namespace App\Exceptions;

use App\Enums\DirectoryRetryMode;

class ActiveDirectoryException extends DirectoryResetException
{
    public function __construct(
        string $message,
        string $reason,
        DirectoryRetryMode $retryMode = DirectoryRetryMode::None,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $reason, $retryMode, $previous);
    }
}
