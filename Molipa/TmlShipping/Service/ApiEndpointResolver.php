<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Magento\Framework\App\State;

final class ApiEndpointResolver
{
    private const API_BASE_URL_DEV  = 'http://localhost:8080';
    private const API_BASE_URL_PROD = 'http://localhost:8081';

    public function __construct(
        private readonly State $appState
    ) {}

    public function resolveStoresUrl(): string
    {
        return $this->resolveBaseUrl() . '/stores';
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
