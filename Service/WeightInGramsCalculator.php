<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

final class WeightInGramsCalculator
{
    private const XML_PATH_WEIGHT_UNIT = 'general/locale/weight_unit';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function fromPackageWeight(float $packageWeight, int $storeId): int
    {
        $unit = (string)$this->scopeConfig->getValue(
            self::XML_PATH_WEIGHT_UNIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $unit = strtolower(trim($unit));
        if ($unit === '') {
            $unit = 'kgs';
        }

        return $this->toGrams($packageWeight, $unit);
    }

    private function toGrams(float $weight, string $unit): int
    {
        if ($weight <= 0) {
            return 0;
        }

        if ($unit === 'lbs' || $unit === 'lb') {
            return (int)round($weight * 453.59237);
        }

        return (int)round($weight * 1000);
    }
}
