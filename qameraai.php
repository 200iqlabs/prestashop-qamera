<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use QameraAi\Module\Install\Installer;

class QameraAi extends Module
{
    public function __construct()
    {
        $this->name = 'qameraai';
        $this->author = '200iq Labs';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->tab = 'administration';

        parent::__construct();

        $this->displayName = $this->trans('Qamera AI', [], 'Modules.Qameraai.Admin');
        $this->description = $this->trans(
            'AI-powered product photography: packshots and full sessions from your store products.',
            [],
            'Modules.Qameraai.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to uninstall Qamera AI? Saved API credentials and local product/packshot links will be removed; assets already generated on Qamera AI side are unaffected.',
            [],
            'Modules.Qameraai.Admin'
        );
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        return (new Installer($this))->install();
    }

    public function uninstall(): bool
    {
        return (new Installer($this))->uninstall() && parent::uninstall();
    }

    public function getContent(): string
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminQameraAiConfiguration')
        );

        return '';
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Stub for the `actionProductAdd` hook registered by the installer.
     * PrestaShop 9.0+ validates that every registered hook has a matching
     * `hookXxx` method on the Module class at install time. The real
     * implementation lands in Phase 3 (`ProductSyncService`), wired via
     * the back-office "Auto-register new products" toggle.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionProductAdd(array $params): void
    {
    }

    /**
     * Stub for the `actionProductUpdate` hook registered by the installer.
     * See `hookActionProductAdd` for the Phase 3 plan.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionProductUpdate(array $params): void
    {
    }
}
