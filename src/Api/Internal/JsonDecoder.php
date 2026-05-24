<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Internal;

use QameraAi\Module\Api\Exception\ValidationException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Decodes an associative-array payload into a typed DTO via constructor
 * reflection. Unknown server-side fields are ignored (forward compat).
 * Missing required fields throw {@see ValidationException::malformedResponse()}.
 *
 * Nested DTOs and `array<DTO>` collections are resolved when the parameter
 * type is a class-string under the same namespace.
 */
final class JsonDecoder
{
    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $payload
     *
     * @return T
     */
    public function decode(string $class, array $payload): object
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            /** @var T */
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            $snake = $this->camelToSnake($name);
            $key = array_key_exists($snake, $payload)
                ? $snake
                : (array_key_exists($name, $payload) ? $name : null);

            if ($key === null) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();

                    continue;
                }
                if ($param->allowsNull()) {
                    $args[] = null;

                    continue;
                }

                throw ValidationException::malformedResponse($snake);
            }

            $args[] = $this->coerce($payload[$key], $param);
        }

        /** @var T */
        return $ref->newInstanceArgs($args);
    }

    private function coerce(mixed $value, \ReflectionParameter $param): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $param->getType();
        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // array<DTO> via #[ArrayOf(X::class)] attribute on the parameter.
        if ($typeName === 'array' && is_array($value)) {
            $attrs = $param->getAttributes(ArrayOf::class);
            if ($attrs !== []) {
                $elementClass = $attrs[0]->newInstance()->elementClass;
                $out = [];
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $out[] = $this->decode($elementClass, $item);
                    } else {
                        $out[] = $item;
                    }
                }

                return $out;
            }

            return $value;
        }

        if ($type->isBuiltin()) {
            return $value;
        }

        // Nested DTO.
        if (is_array($value) && class_exists($typeName)) {
            /** @var class-string $typeName */
            return $this->decode($typeName, $value);
        }

        return $value;
    }

    private function camelToSnake(string $name): string
    {
        return strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name));
    }
}
