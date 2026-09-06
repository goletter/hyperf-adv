<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\Google\Exceptions;

use Goletter\Adv\Exceptions\TokenExpiredExceptionInterface;

class GoogleTokenExpiredException extends GoogleApiException implements TokenExpiredExceptionInterface
{
}
