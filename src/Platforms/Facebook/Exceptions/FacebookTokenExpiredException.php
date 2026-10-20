<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Facebook\Exceptions;

use Goletter\Adv\Exceptions\TokenExpiredExceptionInterface;

class FacebookTokenExpiredException extends FacebookApiException implements TokenExpiredExceptionInterface
{
}
