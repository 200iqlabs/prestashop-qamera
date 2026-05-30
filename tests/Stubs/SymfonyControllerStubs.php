<?php

declare(strict_types=1);

// Minimal stubs for the Symfony/PrestaShop classes the admin controllers touch,
// so unit tests can drive a controller's payload assembly without booting the
// PS/Symfony kernel. PrestaShop provides the real classes at runtime (and the
// integration suite runs inside the container), so each stub is guarded by
// class_exists and is skipped whenever the genuine class is autoloadable.

namespace PrestaShopBundle\Controller\Admin {
    if (!\class_exists(FrameworkBundleAdminController::class)) {
        class FrameworkBundleAdminController
        {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, string $domain = 'messages', array $parameters = [], ?string $locale = null): string
            {
                return $id;
            }
        }
    }
}

namespace Symfony\Component\HttpFoundation {
    if (!\class_exists(ParameterBag::class)) {
        class ParameterBag
        {
            /** @param array<string, mixed> $parameters */
            public function __construct(private array $parameters = [])
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->parameters[$key] ?? $default;
            }
        }
    }

    if (!\class_exists(Request::class)) {
        class Request
        {
            public ParameterBag $query;

            /** @param array<string, mixed> $query */
            public function __construct(array $query = [])
            {
                $this->query = new ParameterBag($query);
            }
        }
    }

    if (!\class_exists(JsonResponse::class)) {
        class JsonResponse
        {
            /** @param mixed $data */
            public function __construct(private mixed $data = null, private int $status = 200)
            {
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            public function getContent(): string
            {
                return (string) \json_encode($this->data);
            }

            public function setPrivate(): self
            {
                return $this;
            }

            public function setMaxAge(int $value): self
            {
                return $this;
            }
        }
    }
}
