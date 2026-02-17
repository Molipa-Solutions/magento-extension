<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Magento\Framework\App\State;

final class ApiEndpointResolver
{
    private const API_BASE_URL_DEV  = 'https://api.molipa-tml.dev/v1/clients/magento';
    private const API_BASE_URL_PROD = 'https://api.molipa-tml.com.ar/v1/clients/magento';

    /** @var State */
    private $appState;

    public function __construct(
        State $appState
    ) {
        $this->appState = $appState;
    }

    public function resolveStoresUrl(): string
    {
        return $this->resolveBaseUrl() . '/stores';
    }

    public function resolveWebhookUrl(): string
    {
        return $this->resolveBaseUrl() . '/webhooks';
    }

    public function resolveRatesUrl(): string
    {
        return $this->resolveBaseUrl() . '/carrier/rates';
    }

    public function resolveBaseUrl(): string
    {
        $mode = $this->appState->getMode();

        $base = ($mode === State::MODE_DEVELOPER)
            ? self::API_BASE_URL_DEV
            : self::API_BASE_URL_PROD;

        return rtrim($base, '/');
    }
}
