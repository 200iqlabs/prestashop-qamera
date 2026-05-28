<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Packshot\SyncedProductLink;
use QameraAi\Module\Packshot\SyncedProductLinkLookup;

/**
 * In-memory replacement for {@see SyncedProductLinkLookup}. The
 * `byIdProduct` map is keyed `id_product → SyncedProductLink`. Tests
 * populate it in setUp().
 */
final class FakeSyncedProductLinkLookup extends SyncedProductLinkLookup
{
    /** @var array<int, SyncedProductLink> */
    public array $byIdProduct = [];

    public function __construct()
    {
        // Bypass parent — no Db dependency in the fake.
    }

    /**
     * @param int[] $idProducts
     *
     * @return array<int, SyncedProductLink>
     */
    public function loadByProductIds(int $idShop, array $idProducts): array
    {
        $out = [];
        foreach ($idProducts as $idProduct) {
            $link = $this->byIdProduct[$idProduct] ?? null;
            if ($link !== null && $link->idShop === $idShop) {
                $out[$idProduct] = $link;
            }
        }
        return $out;
    }

    public function findIdLink(int $idShop, int $idProduct): ?int
    {
        $link = $this->byIdProduct[$idProduct] ?? null;
        if ($link !== null && $link->idShop === $idShop) {
            return $link->idLink;
        }
        return null;
    }

    public function findByIdLink(int $idShop, int $idLink): ?SyncedProductLink
    {
        foreach ($this->byIdProduct as $link) {
            if ($link->idLink === $idLink && $link->idShop === $idShop) {
                return $link;
            }
        }
        return null;
    }
}
