<?php

declare(strict_types=1);

namespace Goletter\Adv\Platforms\TikTok\Exceptions;

use Goletter\Adv\Exceptions\TokenExpiredExceptionInterface;

class TikTokTokenExpiredException extends TikTokApiException implements TokenExpiredExceptionInterface
{
}
