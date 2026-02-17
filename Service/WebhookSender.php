<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Magento\Framework\HTTP\Client\Curl;
use Molipa\TmlShipping\Model\Config;
use Psr\Log\LoggerInterface;

class WebhookSender
{
    /** @var Curl */
    private $curl;

    /** @var Config */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var ApiEndpointResolver */
    private $apiEndpointResolver;

    /** @var HmacSigner */
    private $hmacSigner;

    public function __construct(
        Curl $curl,
        Config $config,
        LoggerInterface $logger,
        ApiEndpointResolver $apiEndpointResolver,
        HmacSigner $hmacSigner
    ) {
        $this->curl = $curl;
        $this->config = $config;
        $this->logger = $logger;
        $this->apiEndpointResolver = $apiEndpointResolver;
        $this->hmacSigner = $hmacSigner;
    }

    public function sendOrders(array $payload, string $eventId, int $websiteId): void
    {
        $url = $this->apiEndpointResolver->resolveWebhookUrl();
        if (!$url) {
            return;
        }

        $provider = 'MAGENTO';
        $clientId = $this->config->getClientIdForWebsite($websiteId);
        $secret = $this->config->getClientSecretForWebsite($websiteId);
        $eventType = 'orders/fulfilled';

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Failed to JSON encode webhook payload');
        }

        $eventId = trim($eventId);
        if ($eventId === '') {
            $eventId = bin2hex(random_bytes(16));
        }

        $signatureBase64 = $this->hmacSigner->signCanonicalWithBodyHashBase64(
            [$provider, $clientId, $eventType, $eventId],
            $body,
            $secret
        );

        $headers = [
            'Content-Type' => 'application/json',
            'X-Provider' => $provider,
            'X-ClientId' => $clientId,
            'X-EventType' => $eventType,
            'X-EventId' => $eventId,
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
