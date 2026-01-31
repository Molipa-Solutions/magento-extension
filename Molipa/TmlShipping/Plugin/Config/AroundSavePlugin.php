<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Plugin\Config;

use Magento\Config\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Molipa\TmlShipping\Service\ApiEndpointResolver;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\RegionFactory;
use Psr\Log\LoggerInterface;
use Molipa\TmlShipping\Config\ConfigPaths;


class AroundSavePlugin
{
    private const PATH_ENABLED = 'tmlshipping/general/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger,
        private readonly ApiEndpointResolver $apiEndpointResolver,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly CountryFactory $countryFactory,
        private readonly RegionFactory $regionFactory,
        private readonly WriterInterface $configWriter
    ) {}

    public function aroundSave(Config $subject, callable $proceed): Config
    {
        if (!$this->isTmlShippingSection($subject)) {
            return $proceed();
        }

        $ctx = $this->resolveWebsiteContext($subject);
        if ($ctx === null) {
            return $proceed();
        }

        $newValue = $this->extractEnabledFromPost($subject);
        if ($newValue === null) {
            return $proceed();
        }

        $oldValue = $this->readEnabledBeforeSave($ctx['websiteCode']);

        /** @var Config $result */
        $result = $proceed();

        if (!$this->isOffToOn($oldValue, $newValue)) {
            return $result;
        }

        $payload = $this->buildCreateStorePayload($ctx['websiteCode'], $ctx['websiteId']);

        $apiResult = $this->callCreateStore($ctx['websiteCode'], $ctx['websiteId'], $payload);
        if ($apiResult === null) {
            return $result;
        }

        $this->persistCredentials($ctx['websiteId'], $apiResult);

        return $result;
    }


    private function isTmlShippingSection(Config $subject): bool
    {
        return (string)$subject->getSection() === 'tmlshipping';
    }


    private function resolveWebsiteContext(Config $subject): ?array
    {
        $websiteCode = (string)($subject->getWebsite() ?? '');
        $storeCode   = (string)($subject->getStore() ?? '');

        if ($storeCode !== '' || $websiteCode === '') {
            return null;
        }

        $websiteId = (int)$this->storeManager->getWebsite($websiteCode)->getId();

        return [
            'websiteCode' => $websiteCode,
            'websiteId'   => $websiteId,
        ];
    }

    private function extractEnabledFromPost(Config $subject): ?int
    {
        $groups = (array)$subject->getGroups();
        $raw = $groups['general']['fields']['enabled']['value'] ?? null;
        return $raw === null ? null : (int)$raw;
    }

    private function readEnabledBeforeSave(string $websiteCode): int
    {
        return (int)$this->scopeConfig->getValue(
            self::PATH_ENABLED,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteCode
        );
    }

    private function isOffToOn(int $oldValue, int $newValue): bool
    {
        return $oldValue === 0 && $newValue === 1;
    }

    private function callCreateStore(string $websiteCode, int $websiteId, array $payload): ?array
    {
        try {
            $this->configureCurlForJson();
            $this->curl->post($this->apiEndpointResolver->resolveStoresUrl(), $this->encodeJson($payload));

            $status = (int)$this->curl->getStatus();
            $body   = (string)$this->curl->getBody();

            $this->logger->info(sprintf(
                '[TmlShipping] POST /stores website="%s" websiteId=%d status=%d body=%s payload=%s',
                $websiteCode,
                $websiteId,
                $status,
                $body,
                $this->encodeJson($payload)
            ));

            if (!$this->is2xx($status)) {
                $this->logger->error(sprintf(
                    '[TmlShipping] /stores failed website="%s" status=%d body=%s',
                    $websiteCode,
                    $status,
                    $body
                ));
                return null;
            }

            $decoded = $this->decodeJson($body);
            if ($decoded === null) {
                $this->logger->error(sprintf(
                    '[TmlShipping] /stores invalid JSON website="%s" body=%s',
                    $websiteCode,
                    $body
                ));
                return null;
            }

            return $decoded;

        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[TmlShipping] POST /stores FAILED website="%s" websiteId=%d url=%s error=%s',
                $websiteCode,
                $websiteId,
                $this->apiEndpointResolver->resolveStoresUrl(),
                $e->getMessage()
            ));
            return null;
        }
    }

    private function configureCurlForJson(): void
    {
        $this->curl->setTimeout(8);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-Provider', 'magento');
    }

    private function is2xx(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    private function encodeJson(array $payload): string
    {
        return (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(string $body): ?array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }


    private function persistCredentials(int $websiteId, array $response): void
    {
        $clientId = $this->getStringOrNull($response, 'clientId');
        $secret   = $this->getStringOrNull($response, 'clientSecret');

        if ($clientId !== null) {
            $this->saveWebsiteConfig(ConfigPaths::CLIENT_ID, $clientId, $websiteId);
        }

        if ($secret !== null) {
            $this->saveWebsiteConfig(ConfigPaths::CLIENT_SECRET, $secret, $websiteId);
        }

        $this->scopeConfig->clean();
    }

    private function saveWebsiteConfig(string $path, string $value, int $websiteId): void
    {
        $this->configWriter->save(
            $path,
            $value,
            ScopeInterface::SCOPE_WEBSITES,
            $websiteId
        );
    }

    private function getStringOrNull(array $arr, string $key): ?string
    {
        if (!isset($arr[$key])) return null;
        $v = (string)$arr[$key];
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    private function buildCreateStorePayload(string $websiteCode, int $websiteId): array
    {
        return [
            'providerWebsiteId'   => $websiteId,
            'providerStoreName'   => $this->resolveStoreName($websiteCode),
            'providerStoreEmail'  => $this->resolveStoreEmail($websiteCode),
            'providerStoreDomain' => $this->resolveBaseDomain($websiteCode),
            'country'             => $this->resolveCountryName($this->readCountryId($websiteCode)),
            'province'            => $this->resolveRegionName($this->readRegionId($websiteCode)),
            'mainLanguage'        => $this->readConfigString($websiteCode, 'general/locale/code'),
            'mainCurrency'        => $this->resolveCurrency($websiteCode),
            'mainTimezone'        => $this->readConfigString($websiteCode, 'general/locale/timezone'),
            'edition'             => $this->resolveEdition(),
        ];
    }

    private function resolveBaseDomain(string $websiteCode): ?string
    {
        $secure   = $this->readConfigString($websiteCode, 'web/secure/base_url');
        $unsecure = $this->readConfigString($websiteCode, 'web/unsecure/base_url');

        $base = $secure ?: ($unsecure ?: null);
        return $base ? rtrim($base, '/') : null;
    }

    private function resolveStoreName(string $websiteCode): ?string
    {
        $name = $this->readConfigString($websiteCode, 'general/store_information/name');
        if ($name) return $name;

        try {
            $fallback = (string)$this->storeManager->getWebsite($websiteCode)->getName();
            $fallback = trim($fallback);
            return $fallback === '' ? null : $fallback;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveStoreEmail(string $websiteCode): ?string
    {
        return $this->readConfigString($websiteCode, 'trans_email/ident_general/email');
    }

    private function readCountryId(string $websiteCode): string
    {
        return (string)$this->scopeConfig->getValue(
            'general/store_information/country_id',
            ScopeInterface::SCOPE_WEBSITE,
            $websiteCode
        );
    }

    private function readRegionId(string $websiteCode): string
    {
        return (string)$this->scopeConfig->getValue(
            'general/store_information/region_id',
            ScopeInterface::SCOPE_WEBSITE,
            $websiteCode
        );
    }

    private function readConfigString(string $websiteCode, string $path): ?string
    {
        $v = (string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $websiteCode);
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    private function resolveCurrency(string $websiteCode): ?string
    {
        $cur = $this->readConfigString($websiteCode, 'currency/options/default');
        return $cur ?: $this->readConfigString($websiteCode, 'currency/options/base');
    }

    private function resolveEdition(): ?string
    {
        try {
            $edition = (string)$this->productMetadata->getEdition();
            $edition = trim($edition);
            return $edition === '' ? null : $edition;
        } catch (\Throwable) {
            return null;
        }
    }


    private function resolveCountryName(?string $countryId): ?string
    {
        $countryId = $countryId ? trim($countryId) : '';
        if ($countryId === '') return null;

        try {
            $country = $this->countryFactory->create()->loadByCode($countryId);
            $name = (string)$country->getName();
            $name = trim($name);
            return $name === '' ? null : $name;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[TmlShipping] resolveCountryName failed countryId="%s": %s',
                $countryId,
                $e->getMessage()
            ));
            return null;
        }
    }

    private function resolveRegionName(?string $regionId): ?string
    {
        $regionId = $regionId ? trim($regionId) : '';
        if ($regionId === '' || !ctype_digit($regionId)) return null;

        try {
            $region = $this->regionFactory->create()->load((int)$regionId);
            $name = (string)$region->getDefaultName();
            if (trim($name) === '') {
                $name = (string)$region->getName();
            }
            $name = trim($name);
            return $name === '' ? null : $name;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[TmlShipping] resolveRegionName failed regionId="%s": %s',
                $regionId,
                $e->getMessage()
            ));
            return null;
        }
    }
}
