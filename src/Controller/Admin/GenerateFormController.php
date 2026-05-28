<?php

declare(strict_types=1);

namespace QameraAi\Module\Controller\Admin;

use Context;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use QameraAi\Module\Api\Cache\CachedReferenceClient;
use QameraAi\Module\Api\Cache\CachedReferenceClientFactory;
use QameraAi\Module\Api\Dto\AspectRatio;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\Factory\MissingConfigurationException;
use QameraAi\Module\Packshot\CalculatorBridge;
use QameraAi\Module\Packshot\PackshotJobSubmitter;
use QameraAi\Module\Packshot\SubmitFormInput;
use QameraAi\Module\Packshot\SubmitResult;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the generate-packshots form (GET) and processes its submission
 * (POST). Form fields per the `qamera-bo-ui` spec; reference data
 * (ai_models, sceneries, mannequins, presets, aspect_ratios) comes from
 * the TTL-cached client so re-renders don't hammer upstream.
 *
 * On submit:
 *   - validates ai_model / aspect_ratio / images_count server-side
 *   - calls PackshotJobSubmitter with the SubmitFormInput
 *   - on full success: flash success + redirect to jobs history
 *   - on partial / full failure: flash error(s) and redirect to history
 *     so the operator can see partial state; ApiValidationException
 *     errors re-render the form with field-level messages
 */
final class GenerateFormController extends FrameworkBundleAdminController
{
    public function showAction(
        Request $request,
        CachedReferenceClientFactory $referenceFactory
    ): Response {
        $productIds = $this->parseProductIds($request->query->get('products', ''));

        try {
            $reference = $referenceFactory->create();
            $context = $this->loadReferenceContext($reference);
        } catch (MissingConfigurationException) {
            $this->addFlash('error', $this->trans(
                'Qamera AI API key is not configured. Save your credentials first.',
                'Modules.Qameraai.Admin'
            ));
            return $this->redirectToRoute('_qameraai_admin_configuration');
        } catch (ApiException $e) {
            $this->addFlash('error', $this->trans(
                'Could not load Qamera AI reference data: %message%',
                'Modules.Qameraai.Admin',
                ['%message%' => $e->getMessage()]
            ));
            return $this->redirectToRoute('_qameraai_admin_products_grid');
        }

        return $this->render(
            '@Modules/qameraai/views/templates/admin/generate_form.html.twig',
            $context + [
                'product_ids' => $productIds,
                'default_aspect_ratio' => $this->resolveDefaultAspectRatio($context['aspect_ratios']),
                'default_images_count' => 4,
                'errors' => [],
                'submit_url' => $this->generateUrl('_qameraai_admin_generate_submit'),
                'products_url' => $this->generateUrl('_qameraai_admin_products_grid'),
            ]
        );
    }

    public function submitAction(
        Request $request,
        PackshotJobSubmitter $submitter,
        CachedReferenceClientFactory $referenceFactory
    ): Response {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('qamera_generate_submit', $token)) {
            $this->addFlash('error', $this->trans('Invalid CSRF token.', 'Modules.Qameraai.Admin'));
            return $this->redirectToRoute('_qameraai_admin_products_grid');
        }

        $aiModel = trim((string) $request->request->get('ai_model'));
        $aspectRatio = trim((string) $request->request->get('aspect_ratio', '1:1'));
        $imagesCount = (int) $request->request->get('images_count', 4);
        $sceneryId = $this->emptyToNull($request->request->get('scenery_id'));
        $mannequinModelId = $this->emptyToNull($request->request->get('mannequin_model_id'));
        $presetId = $this->emptyToNull($request->request->get('preset_id'));
        $suggestions = $this->emptyToNull($request->request->get('suggestions'));
        $productIds = $this->parseProductIds($request->request->get('product_ids', ''));

        $errors = $this->validate($aiModel, $imagesCount, $productIds);
        if ($errors !== []) {
            return $this->renderWithErrors($referenceFactory, $request, $errors);
        }

        $idShop = $this->resolveShopId();
        try {
            $input = new SubmitFormInput(
                idShop: $idShop,
                productIds: $productIds,
                aiModel: $aiModel,
                aspectRatio: $aspectRatio,
                imagesCount: $imagesCount,
                sceneryId: $sceneryId,
                mannequinModelId: $mannequinModelId,
                presetId: $presetId,
                suggestions: $suggestions,
            );
        } catch (\InvalidArgumentException $e) {
            $errors['general'] = $e->getMessage();
            return $this->renderWithErrors($referenceFactory, $request, $errors);
        }

        try {
            $result = $submitter->submit($input);
        } catch (ValidationException $e) {
            $errors['ai_model'] = $e->getMessage();
            return $this->renderWithErrors($referenceFactory, $request, $errors);
        }

        $this->flashResult($result);
        return $this->redirectToRoute('_qameraai_admin_jobs_history');
    }

    /**
     * Pre-flight cost endpoint used by the form's JS to recompute when
     * the operator changes `ai_model`, `images_count`, or the subject
     * selection. Returns JSON `{cost: int|null, currency: string}`.
     */
    public function costAction(
        Request $request,
        CalculatorBridge $bridge
    ): Response {
        $aiModel = (string) $request->query->get('ai_model', '');
        $imagesCount = (int) $request->query->get('images_count', 1);
        $subjectCount = (int) $request->query->get('subjects', 1);

        try {
            $cost = $bridge->estimate($aiModel, $imagesCount, $subjectCount);
        } catch (MissingConfigurationException | ApiException) {
            return $this->json(['cost' => null, 'currency' => null]);
        }

        return $this->json(['cost' => $cost, 'currency' => 'credits']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadReferenceContext(CachedReferenceClient $reference): array
    {
        return [
            'ai_models' => $reference->listAiModels(),
            'sceneries' => array_filter(
                $reference->listSceneries(),
                static fn ($s): bool => ($s->status ?? null) !== 'archived'
            ),
            'mannequins' => $reference->listMannequinModels(),
            'presets' => $reference->listPresets(),
            'aspect_ratios' => $reference->listAspectRatios(),
        ];
    }

    /**
     * @param AspectRatio[] $aspectRatios
     */
    private function resolveDefaultAspectRatio(array $aspectRatios): string
    {
        foreach ($aspectRatios as $ar) {
            if ($ar->default) {
                return $ar->value;
            }
        }
        return $aspectRatios[0]->value ?? '1:1';
    }

    /**
     * @param int[] $productIds
     *
     * @return array<string, string>  field => message
     */
    private function validate(string $aiModel, int $imagesCount, array $productIds): array
    {
        $errors = [];
        if ($aiModel === '') {
            $errors['ai_model'] = $this->trans('AI model is required.', 'Modules.Qameraai.Admin');
        }
        if ($imagesCount < 1 || $imagesCount > 50) {
            $errors['images_count'] = $this->trans(
                'Images per subject must be between 1 and 50.',
                'Modules.Qameraai.Admin'
            );
        }
        if ($productIds === []) {
            $errors['products'] = $this->trans('Select at least one product.', 'Modules.Qameraai.Admin');
        }
        return $errors;
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderWithErrors(
        CachedReferenceClientFactory $referenceFactory,
        Request $request,
        array $errors
    ): Response {
        try {
            $context = $this->loadReferenceContext($referenceFactory->create());
        } catch (MissingConfigurationException | ApiException) {
            // Already failed once — degrade to a flash + redirect rather
            // than render an empty form.
            $this->addFlash('error', implode(' ', $errors));
            return $this->redirectToRoute('_qameraai_admin_products_grid');
        }

        return $this->render(
            '@Modules/qameraai/views/templates/admin/generate_form.html.twig',
            $context + [
                'product_ids' => $this->parseProductIds($request->request->get('product_ids', '')),
                'default_aspect_ratio' => trim((string) $request->request->get('aspect_ratio', '1:1')),
                'default_images_count' => (int) $request->request->get('images_count', 4),
                'errors' => $errors,
                'submit_url' => $this->generateUrl('_qameraai_admin_generate_submit'),
                'products_url' => $this->generateUrl('_qameraai_admin_products_grid'),
                'previous_input' => $request->request->all(),
            ]
        );
    }

    private function flashResult(SubmitResult $result): void
    {
        if ($result->isFullSuccess()) {
            $this->addFlash('success', $this->trans(
                'Submitted %sessions% session(s); %jobs% job(s) queued. Order(s): %orders%.',
                'Modules.Qameraai.Admin',
                [
                    '%sessions%' => $result->sessionsSubmitted,
                    '%jobs%' => $result->jobsPersisted,
                    '%orders%' => implode(', ', $result->orderIds),
                ]
            ));
            return;
        }

        if ($result->isFullFailure()) {
            $this->addFlash('error', $this->trans(
                'All sessions failed: %message%',
                'Modules.Qameraai.Admin',
                ['%message%' => implode(' / ', $result->chunkFailures) ?: 'unknown error']
            ));
            return;
        }

        $this->addFlash('warning', $this->trans(
            'Partial success: %ok% session(s) queued (%jobs% jobs), %failed% failed. Details in logs.',
            'Modules.Qameraai.Admin',
            [
                '%ok%' => $result->sessionsSubmitted,
                '%jobs%' => $result->jobsPersisted,
                '%failed%' => $result->sessionsFailed,
            ]
        ));
    }

    /**
     * @return int[]
     *
     * @param string|null|array<int, mixed> $raw
     */
    private function parseProductIds($raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $items = $raw === null ? [] : array_map('trim', explode(',', (string) $raw));
        }
        $out = [];
        foreach ($items as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param mixed $value
     */
    private function emptyToNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    private function resolveShopId(): int
    {
        $context = Context::getContext();
        if ($context->shop !== null && isset($context->shop->id)) {
            return (int) $context->shop->id;
        }
        return 1;
    }
}
