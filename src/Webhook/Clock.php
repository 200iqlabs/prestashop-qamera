<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

interface Clock
{
    public function nowEpoch(): int;
}
