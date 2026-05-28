<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration;

use Db;

/**
 * Suite-wide sweep of fixtures whose identifiers carry the reserved
 * `TEST-` prefix (see `integration-test-harness` spec §4). Idempotent
 * — empty result sets are fine.
 *
 * Deletes in dependency order:
 *  1. `ps_qamera_product_link` rows whose product carries a `TEST-`
 *     reference (join, then delete by id_product).
 *  2. `ps_product` rows with `reference LIKE 'TEST-%'` plus their
 *     `ps_product_shop` shadow rows.
 *  3. Orphan `ps_image` rows whose `id_product` no longer exists in
 *     `ps_product` (catches leftovers from interrupted runs).
 */
function cleanupTestFixtures(Db $db): void
{
    $prefix = _DB_PREFIX_;

    $db->execute(
        'DELETE pl FROM `' . $prefix . 'qamera_product_link` pl'
        . ' INNER JOIN `' . $prefix . 'product` p ON p.id_product = pl.id_product'
        . " WHERE p.reference LIKE 'TEST-%'"
    );

    $db->execute(
        'DELETE ps FROM `' . $prefix . 'product_shop` ps'
        . ' INNER JOIN `' . $prefix . 'product` p ON p.id_product = ps.id_product'
        . " WHERE p.reference LIKE 'TEST-%'"
    );

    $db->execute(
        'DELETE FROM `' . $prefix . "product` WHERE reference LIKE 'TEST-%'"
    );

    $db->execute(
        'DELETE i FROM `' . $prefix . 'image` i'
        . ' LEFT JOIN `' . $prefix . 'product` p ON p.id_product = i.id_product'
        . ' WHERE p.id_product IS NULL'
    );
}

/**
 * Per-test variant scoped to a single marker — invoked from
 * `IntegrationTestCase::tearDown`. The same dependency order applies.
 */
function cleanupTestFixturesByMarker(Db $db, string $marker): void
{
    $prefix = _DB_PREFIX_;
    $like = "'TEST-" . $db->escape($marker, false, true) . "-%'";

    $db->execute(
        'DELETE pl FROM `' . $prefix . 'qamera_product_link` pl'
        . ' INNER JOIN `' . $prefix . 'product` p ON p.id_product = pl.id_product'
        . ' WHERE p.reference LIKE ' . $like
    );

    $db->execute(
        'DELETE ps FROM `' . $prefix . 'product_shop` ps'
        . ' INNER JOIN `' . $prefix . 'product` p ON p.id_product = ps.id_product'
        . ' WHERE p.reference LIKE ' . $like
    );

    $db->execute(
        'DELETE FROM `' . $prefix . 'product` WHERE reference LIKE ' . $like
    );

    $db->execute(
        'DELETE i FROM `' . $prefix . 'image` i'
        . ' LEFT JOIN `' . $prefix . 'product` p ON p.id_product = i.id_product'
        . ' WHERE p.id_product IS NULL'
    );
}
