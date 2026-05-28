<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Webhook\Event\ProductLinkHeartbeat;
use QameraAi\Module\Webhook\Event\QameraDbException;

final class FakeProductLinkHeartbeat extends ProductLinkHeartbeat
{
    /** @var list<array{idShop:int, idProduct:int}> */
    public array $touches = [];

    public bool $nextReturns = true;
    public ?QameraDbException $throwNext = null;

    public function __construct()
    {
        // Bypass the parent constructor — this fake never touches a Db.
    }

    public function touch(int $idShop, int $idProduct): bool
    {
        if ($this->throwNext !== null) {
            $e = $this->throwNext;
            $this->throwNext = null;
            throw $e;
        }
        $this->touches[] = ['idShop' => $idShop, 'idProduct' => $idProduct];
        return $this->nextReturns;
    }
}
