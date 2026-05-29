<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Acceptance;

use QameraAi\Module\Api\Exception\ApiException;

/**
 * Classifies a failed `job_type='photo_shoot'` submission so the BO can flash
 * a localized, actionable message instead of a raw 422 (add-packshot-
 * acceptance-flow, "Photo-shoot is gated" requirement, D4 safety net).
 *
 * Two upstream `ErrorEnvelope.code`s are special-cased:
 *  - `packshot_not_approved` — drift: upstream has no accepted packshot for the
 *    product_ref (e.g. rejected upstream after a local accept). → re-accept.
 *  - `invalid_input` — appears when `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` is still
 *    OFF and `packshot_asset_id` was omitted (the backend requires it OFF).
 *    This is a deploy/flag-cutover signal, NOT operator error.
 *
 * The classifier is pure: it returns a {@see PhotoShootSubmitError} discriminator
 * + the server's own localized message; the controller owns the translation +
 * flash (mirrors `SyncedProductLink::getDisabledHint()`'s key-returns-to-the-
 * controller convention).
 */
final class PhotoShootSubmitErrorClassifier
{
    public const CODE_NOT_APPROVED = 'packshot_not_approved';
    public const CODE_INVALID_INPUT = 'invalid_input';

    public function classify(ApiException $e, string $locale): PhotoShootSubmitError
    {
        $envelope = $e->getEnvelope();
        $code = $envelope?->code;
        $serverMessage = $envelope !== null ? $envelope->messageFor($locale) : $e->getMessage();

        if ($code === self::CODE_NOT_APPROVED) {
            return new PhotoShootSubmitError(PhotoShootSubmitError::KIND_NOT_APPROVED, $serverMessage);
        }

        // Gate-disabled (flag OFF upstream): the backend rejects a photo_shoot
        // that omitted packshot_asset_id. The envelope code is `invalid_input`,
        // but be robust to wording/code drift by also matching the distinctive
        // gate-disabled message on either the envelope text or the raw message.
        if (
            $code === self::CODE_INVALID_INPUT
            || $this->looksGateDisabled($serverMessage)
            || $this->looksGateDisabled($e->getMessage())
        ) {
            return new PhotoShootSubmitError(PhotoShootSubmitError::KIND_GATE_DISABLED, $serverMessage);
        }

        return new PhotoShootSubmitError(PhotoShootSubmitError::KIND_OTHER, $serverMessage);
    }

    private function looksGateDisabled(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'acceptance gate is disabled')
            || str_contains($m, 'gate resolution')
            || str_contains($m, 'packshot_asset_id is required');
    }
}
