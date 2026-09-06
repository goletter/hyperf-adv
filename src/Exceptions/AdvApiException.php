<?php

declare(strict_types=1);

namespace Goletter\Adv\Exceptions;

class AdvApiException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        protected array $response = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getResponse(): array
    {
        return $this->response;
    }
}
