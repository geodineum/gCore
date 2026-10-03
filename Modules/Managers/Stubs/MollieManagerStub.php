<?php
declare(strict_types=1);
/**
 * MollieManager Stub
 *
 * Free-tier no-op implementation. Reports itself unconfigured and REFUSES
 * every money-moving call rather than returning a plausible success — a site
 * that believes it took a payment it never took is worse than a site that
 * cannot take payments at all.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\Extensions\MollieManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class MollieManagerStub
 */
class MollieManagerStub implements MollieManagerInterface
{
    /** @var MollieManagerStub|null */
    private static $instance = null;

    /** @var array */
    private $config = [];

    /** @var bool */
    private $initialized = false;

    /** @var bool */
    private static $upgradeNoticeLogged = false;

    /** @var array */
    private $defaultConfig = [
        'enabled'   => false,
        'stub_mode' => true,
        'mode'      => 'unconfigured',
    ];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function initialize(array $config = []): void
    {
        $this->config = array_merge($this->defaultConfig, $config);
        $this->initialized = true;
        $this->noteUpgrade();
    }

    public function getConfig(): array { return $this->config; }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function isInitialized(): bool { return $this->initialized; }

    public function getStatus(): array
    {
        return [
            'manager'     => 'MollieManager',
            'tier'        => 'stub',
            'enabled'     => false,
            'mode'        => 'unconfigured',
            'initialized' => $this->initialized,
            'message'     => 'Mollie integration requires the geodineum/gcore-mollie extension.',
        ];
    }

    public function setApiKey(string $siteId, string $mode, string $apiKey): bool
    {
        return $this->refuse('setApiKey')['ok'];
    }

    public function getMode(string $siteId): string { return 'unconfigured'; }

    public function setMode(string $siteId, string $mode): bool
    {
        return $this->refuse('setMode')['ok'];
    }

    public function getCredentialStatus(string $siteId): array
    {
        return [
            'configured' => false,
            'active'     => 'unconfigured',
            'modes'      => ['test' => false, 'live' => false],
            'message'    => 'No extension — no credential store.',
        ];
    }

    public function verifyCredential(string $siteId): array
    {
        return ['ok' => false, 'mode' => 'unconfigured', 'error' => $this->reason()];
    }

    public function listMethods(string $siteId, array $options = []): array
    {
        return ['ok' => false, 'methods' => [], 'error' => $this->reason()];
    }

    public function createPayment(string $siteId, array $request): array
    {
        return $this->refuse('createPayment');
    }

    public function getPayment(string $siteId, string $paymentId, array $embed = []): array
    {
        return $this->refuse('getPayment');
    }

    public function capturePayment(string $siteId, string $paymentId, ?array $amount = null): array
    {
        return $this->refuse('capturePayment');
    }

    public function refundPayment(string $siteId, string $paymentId, ?array $amount = null, string $description = ''): array
    {
        return $this->refuse('refundPayment');
    }

    public function handleWebhook(string $siteId, array $post, array $headers = [], string $rawBody = ''): array
    {
        // 200 on purpose: an unconfigured site must not make Mollie retry for
        // 26 hours, and a webhook arriving here is a misconfiguration to read
        // in the log, not a delivery to keep failing.
        $this->noteUpgrade();
        return [
            'ok'        => true,
            'status'    => 200,
            'paymentId' => isset($post['id']) && is_string($post['id']) ? $post['id'] : null,
            'applied'   => false,
            'reason'    => $this->reason(),
        ];
    }

    public function splitAmount(array $amount, array $weights): array
    {
        $this->noteUpgrade();
        return ['ok' => false, 'parts' => null, 'error' => $this->reason()];
    }

    public function getWebhookUrl(string $siteId): string { return ''; }

    public function getPaymentRecord(string $siteId, string $paymentId): ?array { return null; }

    public function listPaymentRecords(string $siteId, array $filter = []): array { return []; }

    public function listChargebacks(string $siteId, array $filter = []): array { return []; }

    public function shutdown(): void { $this->initialized = false; }

    private function reason(): string
    {
        return 'MollieManager is a stub; install geodineum/gcore-mollie to transact.';
    }

    private function refuse(string $method): array
    {
        $this->noteUpgrade();
        return ['ok' => false, 'id' => null, 'status' => null, 'checkoutUrl' => null,
                'record' => null, 'error' => $this->reason(), 'method' => $method];
    }

    private function noteUpgrade(): void
    {
        if (self::$upgradeNoticeLogged) {
            return;
        }
        self::$upgradeNoticeLogged = true;
        if (function_exists('error_log')) {
            error_log('[gCore] MollieManager: stub tier — payments are unavailable.');
        }
    }
}
