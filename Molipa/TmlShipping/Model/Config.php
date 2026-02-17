<?php

namespace Molipa\TmlShipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Molipa\TmlShipping\Config\ConfigPaths;

class Config
{

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    public function isEnabledForWebsite(int $websiteId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigPaths::XML_ENABLED,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    public function getClientIdForWebsite(int $websiteId): string
    {
        return (string)$this->scopeConfig->getValue(
            ConfigPaths::CLIENT_ID,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    public function getClientSecretForWebsite(int $websiteId): string
    {
        return (string)$this->scopeConfig->getValue(
            ConfigPaths::CLIENT_SECRET,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }
}
