<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

final class DeliveryOutcome
{
    public const ACCEPTED = 'accepted';
    public const DUPLICATE = 'duplicate';

    private function __construct()
    {
    }
}
