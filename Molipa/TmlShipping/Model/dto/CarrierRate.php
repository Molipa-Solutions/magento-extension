<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Model\Dto;

class CarrierRate
{
    /** @var string */
    private $serviceName;

    /** @var string */
    private $serviceCode;

    /** @var float */
    private $totalPrice;

    /** @var string|null */
    private $currency;

    /** @var string|null */
    private $description;

    /** @var string|null */
    private $minDeliveryDate;

    /** @var string|null */
    private $maxDeliveryDate;

    public function __construct(
        string $serviceName,
        string $serviceCode,
        float $totalPrice,
        ?string $currency = null,
        ?string $description = null,
        ?string $minDeliveryDate = null,
        ?string $maxDeliveryDate = null
    ) {
        $this->serviceName = $serviceName;
        $this->serviceCode = $serviceCode;
        $this->totalPrice = $totalPrice;
        $this->currency = $currency;
        $this->description = $description;
        $this->minDeliveryDate = $minDeliveryDate;
        $this->maxDeliveryDate = $maxDeliveryDate;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['serviceName']) ? (string)$data['serviceName'] : '',
            isset($data['serviceCode']) ? (string)$data['serviceCode'] : '',
            self::toFloat(isset($data['totalPrice']) ? $data['totalPrice'] : 0),
            isset($data['currency']) ? (string)$data['currency'] : null,
            isset($data['description']) ? (string)$data['description'] : null,
            isset($data['minDeliveryDate']) ? (string)$data['minDeliveryDate'] : null,
            isset($data['maxDeliveryDate']) ? (string)$data['maxDeliveryDate'] : null
        );
    }

    private static function toFloat($v): float
    {
        if (is_numeric($v)) {
            return (float)$v;
        }

        if (is_string($v)) {
            return (float)str_replace(',', '.', $v);
        }

        return 0.0;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getServiceCode(): string
    {
        return $this->serviceCode;
    }

    public function getTotalPrice(): float
    {
        return $this->totalPrice;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getMinDeliveryDate(): ?string
    {
        return $this->minDeliveryDate;
    }

    public function getMaxDeliveryDate(): ?string
    {
        return $this->maxDeliveryDate;
    }
}
