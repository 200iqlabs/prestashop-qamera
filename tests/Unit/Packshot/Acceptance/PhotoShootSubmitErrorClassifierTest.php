<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot\Acceptance;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Exception\ErrorEnvelope;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Packshot\Acceptance\PhotoShootSubmitError;
use QameraAi\Module\Packshot\Acceptance\PhotoShootSubmitErrorClassifier;

final class PhotoShootSubmitErrorClassifierTest extends TestCase
{
    private PhotoShootSubmitErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new PhotoShootSubmitErrorClassifier();
    }

    public function testPackshotNotApprovedIsClassifiedAsNotApprovedWithLocaleMessage(): void
    {
        $e = new ValidationException('rejected', 422, new ErrorEnvelope(
            'packshot_not_approved',
            ['en' => 'No approved packshot', 'pl' => 'Brak zaakceptowanego packshota'],
            false,
            null
        ));

        $result = $this->classifier->classify($e, 'pl');

        self::assertSame(PhotoShootSubmitError::KIND_NOT_APPROVED, $result->kind);
        self::assertSame('Brak zaakceptowanego packshota', $result->serverMessage);
    }

    public function testInvalidInputIsClassifiedAsGateDisabled(): void
    {
        $e = new ValidationException('bad', 422, new ErrorEnvelope(
            'invalid_input',
            ['en' => 'packshot_asset_id is required'],
            false,
            null
        ));

        $result = $this->classifier->classify($e, 'en');

        self::assertSame(PhotoShootSubmitError::KIND_GATE_DISABLED, $result->kind);
    }

    public function testUnknownEnvelopeCodeIsOther(): void
    {
        $e = new ValidationException('nope', 422, new ErrorEnvelope('something_else', ['en' => 'x'], false, null));

        self::assertSame(PhotoShootSubmitError::KIND_OTHER, $this->classifier->classify($e, 'en')->kind);
    }

    public function testNoEnvelopeFallsBackToExceptionMessage(): void
    {
        $e = new ServerException('upstream 503', 503);

        $result = $this->classifier->classify($e, 'en');

        self::assertSame(PhotoShootSubmitError::KIND_OTHER, $result->kind);
        self::assertSame('upstream 503', $result->serverMessage);
    }

    public function testGateDisabledMessageWithoutInvalidInputCodeIsStillGateDisabled(): void
    {
        // Real wire message seen in smoke (2026-05-29), envelope code may not
        // be exactly `invalid_input` — the distinctive text must still map.
        $e = new ValidationException(
            'subjects[0].packshot_asset_id is required when the photo-shoot '
            . 'acceptance gate is disabled (gate resolution unavailable)',
            422
        );

        self::assertSame(
            PhotoShootSubmitError::KIND_GATE_DISABLED,
            $this->classifier->classify($e, 'en')->kind
        );
    }

    public function testGateDisabledDetectedViaEnvelopeMessageText(): void
    {
        $e = new ValidationException('bad', 422, new ErrorEnvelope(
            'some_other_code',
            ['en' => 'packshot_asset_id is required when the acceptance gate is disabled'],
            false,
            null
        ));

        self::assertSame(
            PhotoShootSubmitError::KIND_GATE_DISABLED,
            $this->classifier->classify($e, 'en')->kind
        );
    }
}
