<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Tab;

use QameraAi\Module\Gallery\WriteScopeChecker;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Renders the product-detail "Qamera" tab fragment.
 *
 * Lives as a DI service rather than inline in the module hook because the
 * compiled PrestaShop container exposes `twig`, `router`, and
 * `security.csrf.token_manager` as PRIVATE services — `$container->get(...)`
 * on them throws "service has been removed or inlined". Dependency injection
 * can wire private services, so the hook resolves this one public service via
 * `$this->get()` and lets the container inject the rest.
 */
final class GalleryTabRenderer
{
    private const TEMPLATE = '@Modules/qameraai/views/templates/admin/product_tab.html.twig';

    public function __construct(
        private readonly Environment $twig,
        private readonly RouterInterface $router,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly WriteScopeChecker $scopeChecker,
    ) {
    }

    /**
     * @param array<int, array{id_image:int, thumb_url:string, is_cover:bool}> $galleryImages
     * @param array<string, string>                                            $i18n
     */
    public function render(int $idProduct, array $galleryImages, array $i18n): string
    {
        $writeScope = $this->resolveWriteScope();

        $config = [
            'idProduct' => $idProduct,
            'writeScope' => $writeScope,
            'urls' => [
                'ingest' => $this->router->generate('_qameraai_admin_gallery_ingest', ['idProduct' => $idProduct]),
                'status' => $this->router->generate('_qameraai_admin_gallery_status', ['idProduct' => $idProduct]),
                'browse' => $this->router->generate('_qameraai_admin_gallery_browse', ['idProduct' => $idProduct]),
                'sessionsTemplate' => $this->router->generate(
                    '_qameraai_admin_gallery_sessions',
                    ['idProduct' => $idProduct, 'imageId' => '__IMAGE__']
                ),
                'importOutput' => $this->router->generate('_qameraai_admin_gallery_import', ['idProduct' => $idProduct]),
            ],
            'token' => [
                'ingest' => $this->csrfTokenManager->getToken('qamera_gallery_ingest')->getValue(),
                'import' => $this->csrfTokenManager->getToken('qamera_gallery_import')->getValue(),
            ],
            'i18n' => $i18n,
        ];

        return $this->twig->render(self::TEMPLATE, [
            'id_product' => $idProduct,
            'write_scope' => $writeScope,
            'gallery_images' => $galleryImages,
            'config_json' => json_encode($config) ?: '{}',
        ]);
    }

    private function resolveWriteScope(): bool
    {
        try {
            return $this->scopeChecker->hasWriteScope();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
