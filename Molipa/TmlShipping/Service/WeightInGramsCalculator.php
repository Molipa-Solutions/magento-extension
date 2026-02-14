<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

final class WeightInGramsCalculator
{
    private const XML_PATH_WEIGHT_UNIT = 'general/locale/weight_unit';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function fromPackageWeight(float $packageWeight, int $storeId): int
    {
        $unit = (string)$this->scopeConfig->getValue(self::XML_PATH_WEIGHT_UNIT, ScopeInterface::SCOPE_STORE, $storeId);
        $unit = strtolower(trim($unit));
        if ($unit === '') $unit = 'kgs';

        return $this->toGrams($packageWeight, $unit);
    }

    private function toGrams(float $weight, string $unit): int
    {
        if ($weight <= 0) return 0;

        return match ($unit) {
            'kgs', 'kg' => (int)round($weight * 1000),
            'lbs', 'lb' => (int)round($weight * 453.59237),
            default => (int)round($weight * 1000),
        };
    }
}
