<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Model\Dto;

final class CarrierRate
{
    public function __construct(
        public readonly string $serviceName,
        public readonly string $serviceCode,
        public readonly float $totalPrice,
        public readonly ?string $currency = null,
        public readonly ?string $description = null,
        public readonly ?string $minDeliveryDate = null,
        public readonly ?string $maxDeliveryDate = null,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['serviceName'] ?? ''),
            (string)($data['serviceCode'] ?? ''),
            self::toFloat($data['totalPrice'] ?? 0),
            isset($data['currency']) ? (string)$data['currency'] : null,
            isset($data['description']) ? (string)$data['description'] : null,
            isset($data['minDeliveryDate']) ? (string)$data['minDeliveryDate'] : null,
            isset($data['maxDeliveryDate']) ? (string)$data['maxDeliveryDate'] : null,
        );
    }

    private static function toFloat(mixed $v): float
    {
        if (is_numeric($v)) return (float)$v;
        if (is_string($v)) return (float)str_replace(',', '.', $v);
        return 0.0;
    }
}
