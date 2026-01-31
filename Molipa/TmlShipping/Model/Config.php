<?php

namespace Molipa\TmlShipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {}

    public function isEnabledForWebsite(int $websiteId): bool
    {
        return $this->scopeConfig->isSetFlag(
            'tmlshipping/general/enabled',
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }
}
