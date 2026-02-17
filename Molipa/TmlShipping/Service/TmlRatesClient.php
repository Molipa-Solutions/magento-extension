<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Molipa\TmlShipping\Model\Config;
use Molipa\TmlShipping\Model\Dto\CarrierRate;

final class TmlRatesClient
{
    /** @var Curl */
    private $curl;
    /** @var Json */
    private $json;
    /** @var LoggerInterface */
    private $logger;
    /** @var Config */
    private $config;
    /** @var ApiEndpointResolver */
    private $apiEndpointResolver;
    /** @var HmacSigner */
    private $hmacSigner;

    public function __construct(
        Curl $curl,
        Json $json,
        LoggerInterface $logger,
        Config $config,
        ApiEndpointResolver $apiEndpointResolver,
        HmacSigner $hmacSigner
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
        $this->config = $config;
        $this->apiEndpointResolver = $apiEndpointResolver;
        $this->hmacSigner = $hmacSigner;
    }

    public function getRate(int $websiteId, int $totalWeightInGrams, string $postalCode): ?CarrierRate
    {
        $endpoint = $this->apiEndpointResolver->resolveRatesUrl();

        $payload = [
            'totalWeightInGrams' => $totalWeightInGrams,
            'postalCode' => $postalCode,
        ];

        $payloadJson = $this->json->serialize($payload);

        $provider = 'MAGENTO';
        $clientId = $this->config->getClientIdForWebsite($websiteId);
        $secret = $this->config->getClientSecretForWebsite($websiteId);

        $hmac = $this->hmacSigner->signCanonicalWithBodyHashBase64([$provider, $clientId], $payloadJson, $secret);

        try {
            $this->curl->addHeader('Content-Type', 'application/json');

            $this->curl->addHeader('X-Provider', $provider);
            $this->curl->addHeader('X-ClientId', $clientId);
            $this->curl->addHeader('X-Hmac-Sha256', $hmac);

            $this->logger->info('[TmlShipping] Sending rates request', [
                'endpoint' => $endpoint,
                'payload' => $payload,
                'websiteId' => $websiteId
            ]);

            $this->curl->post($endpoint, $payloadJson);

            $status = (int)$this->curl->getStatus();
            $body = (string)$this->curl->getBody();

            if ($status < 200 || $status >= 300) {
                $this->logger->error('[TmlShipping] Rates HTTP error', ['status' => $status, 'body' => $body]);
                return null;
            }

            $decoded = $this->json->unserialize($body);

            if (!is_array($decoded)) {
                $this->logger->error('[TmlShipping] Rates response invalid (not array/object)');
                return null;
            }

            return CarrierRate::fromArray($decoded);
        } catch (\Throwable $e) {
            $this->logger->error('[TmlShipping] Rates call failed', ['e' => $e]);
            return null;
        }
    }
}
