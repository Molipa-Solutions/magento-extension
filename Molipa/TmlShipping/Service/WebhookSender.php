<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Magento\Framework\HTTP\Client\Curl;
use Molipa\TmlShipping\Model\Config;
use Psr\Log\LoggerInterface;

class WebhookSender
{
    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ApiEndpointResolver $apiEndpointResolver
    ) {}

    public function sendOrdersPaid(array $payload, string $eventId, int $websiteId): void
    {
        $url = $this->apiEndpointResolver->resolveWebhookUrl();
        if (!$url) {
            return;
        }

        $provider = 'MAGENTO';
        $clientId = $this->config->getClientIdForWebsite($websiteId);
        $secret = $this->config->getClientSecretForWebsite($websiteId);
        $eventType = 'orders/paid';

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Failed to JSON encode webhook payload');
        }

        $eventId = trim($eventId);
        if ($eventId === '') {
            $eventId = bin2hex(random_bytes(16));
        }

        // === HMAC (igual que tu backend) ===
        $bodySha256Hex = hash('sha256', $body);
        $canonical = implode("\n", [
            $provider,
            $clientId,
            $eventType,
            $eventId,
            $bodySha256Hex
        ]);

        $sigRaw = hash_hmac('sha256', $canonical, $secret, true);
        $signatureBase64 = base64_encode($sigRaw);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Provider' => $provider,
            'X-ClientId' => $clientId,
            'X-EventType' => $eventType,
            'X-Nonce' => $eventId,
            'X-Hmac-Sha256' => $signatureBase64,
        ];

        $this->curl->setHeaders($headers);
        $this->curl->post($url, $body);

        $status = (int)$this->curl->getStatus();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning('[TmlShipping] Webhook non-2xx', [
                'status' => $status,
                'response' => $this->curl->getBody(),
                'eventId' => $eventId
            ]);
        }
    }
}
