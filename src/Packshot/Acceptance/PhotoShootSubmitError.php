<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Acceptance;

/**
 * Discriminated result of {@see PhotoShootSubmitErrorClassifier::classify}.
 * `serverMessage` is the upstream's own localized envelope message (or the
 * exception message when there is no envelope); the controller composes the
 * final flash from `kind` + an actionable hint.
 */
final class PhotoShootSubmitError
{
    /** Upstream has no accepted packshot for the product_ref → re-accept. */
    public const KIND_NOT_APPROVED = 'packshot_not_approved';

    /** Flag still OFF upstream (asset_id omitted) → deploy/cutover issue, not operator error. */
    public const KIND_GATE_DISABLED = 'gate_disabled';

    /** Any other API failure → surface the server message as-is. */
    public const KIND_OTHER = 'other';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $serverMessage,
    ) {
    }
}
