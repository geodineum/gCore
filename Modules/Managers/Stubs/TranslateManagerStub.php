<?php
declare(strict_types=1);
/**
 * TranslateManager Stub
 *
 * Graceful no-op implementation for default tier.
 * Returns untranslated content and default language settings.
 * Install the translation extension for multilingual capabilities.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

class TranslateManagerStub implements ModuleInterface
{
    private static $instance = null;
    private $config = [];
    private $initialized = false;
    private static $upgradeNoticeLogged = false;
    private $defaultLanguage = 'en';

    private $capabilityVector = [
        'translation' => 0.0,
        'localization' => 0.0,
        'content_management' => 0.0,
        'language_detection' => 0.0
    ];

    public static function getInstance(): ModuleInterface
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        $this->config = array_merge([
            'stub_mode' => true,
            'default_language' => 'en',
            'site_id' => 'default',
            'node_id' => 'stub',
        ], $config);

        $this->defaultLanguage = $this->config['default_language'];
        $this->initialized = true;
        $this->logUpgradeNotice();
    }

    private function logUpgradeNotice(): void
    {
        if (self::$upgradeNoticeLogged) {
            return;
        }
        self::$upgradeNoticeLogged = true;
        if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) {
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log('[gCore] TranslateManager stub active - the gcore-translate extension provides multilingual support'); }
        }
    }

    public function shutdown(): void {}

    public function getMode(): string
    {
        return 'stub';
    }

    public function getCapabilities(): array
    {
        return [
            'manual_translations' => false,
            'auto_translation' => false,
            'pre_rendered_bundles' => false,
            'stub_mode' => true,
        ];
    }

    public function hasCapability(string $capability): bool
    {
        return false;
    }

    public function getSupportedLanguages(): array
    {
        return [
            $this->defaultLanguage => [
                'name' => 'English',
                'native' => 'English',
                'rtl' => false,
            ],
        ];
    }

    public function getAvailableLanguages(): array
    {
        return $this->getSupportedLanguages();
    }

    public function getCurrentLanguage(): string
    {
        return $this->defaultLanguage;
    }

    public function setCurrentLanguage(string $langCode): bool
    {
        return false;
    }

    public function getDefaultLanguage(): string
    {
        return $this->defaultLanguage;
    }

    public function translateContent(string $content, string $targetLang, ?string $sourceLang = null): string
    {
        return $content; // Return untranslated
    }

    public function getString(string $key, ?string $lang = null): string
    {
        return $key; // Return key as-is
    }

    public function renderLanguageSwitcher(array $options = []): string
    {
        return ''; // No switcher in stub mode
    }

    public function isAvailable(): bool
    {
        return false;
    }

    // ModuleInterface methods

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'stub_mode' => true,
            'mode' => 'stub',
            'default_language' => $this->defaultLanguage,
            'supported_languages' => 1,
            'upgrade_message' => 'The gcore-translate extension provides multilingual content management',
        ];
    }

    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    public function getHealth(): array
    {
        return [
            'status' => 'stub',
            'message' => 'TranslateManager stub - no translation capabilities',
        ];
    }

    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
