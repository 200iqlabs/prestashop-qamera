<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Output;

/**
 * Maps an {@see ImportResult} to a `(http status, json body)` pair for the
 * BO import endpoint. Pure (no PS dependency) so the controller stays a thin
 * shell and the mapping is unit-tested.
 *
 * State vocabulary the JS consumes:
 *   imported          — at least one image written, none failed
 *   partial           — some outputs written, some failed (or all failed)
 *   already_imported  — nothing new; every image output was already in the ledger
 *   nothing           — nothing to place (only non-image outputs recorded, or empty)
 *   aborted           — job-level gate/validation failure; nothing written
 */
final class ImportResultPresenter
{
    private const ABORT_STATUS = [
        'not_completed' => 409,
        'packshot_not_accepted' => 409,
        'invalid_product_ref' => 422,
        'product_not_registered' => 404,
        'api_error' => 502,
    ];

    /**
     * @return array{status:int, json:array<string, mixed>}
     */
    public function present(ImportResult $result): array
    {
        if ($result->reason !== null) {
            return [
                'status' => self::ABORT_STATUS[$result->reason] ?? 400,
                'json' => [
                    'ok' => false,
                    'state' => 'aborted',
                    'reason' => $result->reason,
                    'imported' => [],
                    'skipped' => [],
                    'recorded_non_image' => [],
                    'failures' => [],
                ],
            ];
        }

        $state = $this->state($result);

        return [
            'status' => 200,
            'json' => [
                'ok' => !$result->hasFailures(),
                'state' => $state,
                'imported' => $result->imported,
                'skipped' => $result->skipped,
                'recorded_non_image' => $result->recordedNonImage,
                'failures' => $result->failures,
            ],
        ];
    }

    private function state(ImportResult $result): string
    {
        if ($result->imported !== []) {
            return $result->hasFailures() ? 'partial' : 'imported';
        }
        if ($result->hasFailures()) {
            return 'partial';
        }
        if ($result->skipped !== []) {
            return 'already_imported';
        }
        return 'nothing';
    }
}
