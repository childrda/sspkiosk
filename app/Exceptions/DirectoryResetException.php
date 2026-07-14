<?php

namespace App\Exceptions;

use App\Enums\DirectoryRetryMode;

class DirectoryResetException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly DirectoryRetryMode $retryMode = DirectoryRetryMode::None,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
