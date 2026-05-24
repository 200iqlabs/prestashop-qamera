<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Internal;

use Attribute;

/**
 * Attached to an `array`-typed constructor parameter to declare the element
 * class. {@see JsonDecoder} reads it and maps each payload element through
 * `decode($elementClass, $element)`.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class ArrayOf
{
    /**
     * @param class-string $elementClass
     */
    public function __construct(public readonly string $elementClass)
    {
    }
}
