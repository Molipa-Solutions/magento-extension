<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Molipa\TmlShipping\Model\Dto\CarrierRate;
use Psr\Log\LoggerInterface;
use Molipa\TmlShipping\Model\Config;
use Molipa\TmlShipping\Service\TmlRatesClient;
use Molipa\TmlShipping\Service\WeightInGramsCalculator;
use Magento\Store\Model\StoreManagerInterface;


final class Tml extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'tml';

    public function __construct(
        ScopeConfigInterface                     $scopeConfig,
        ErrorFactory                             $rateErrorFactory,
        LoggerInterface                          $logger,
        private readonly StoreManagerInterface   $storeManager,
        private readonly ResultFactory           $rateResultFactory,
        private readonly MethodFactory           $rateMethodFactory,
        private readonly Config                  $config,
        private readonly TmlRatesClient          $ratesClient,
        private readonly WeightInGramsCalculator $weightCalc,
        array                                    $data = []
    )
    {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function getAllowedMethods(): array
    {
        return ['tml' => 'TML'];
    }

    public function collectRates(RateRequest $request)
    {
        $this->_logger->info('[TmlShipping] collectRates called');

        if (!$this->getConfigFlag('active')) {
            $this->_logger->info('[TmlShipping] Carrier disabled via config');
            return false;
        }

        $storeId = (int)$request->getStoreId();
        $websiteId = (int)$this->storeManager
            ->getStore($storeId)
            ->getWebsiteId();

        if (!$this->config->isEnabledForWebsite($websiteId)) {
            $this->_logger->info('[TmlShipping] Disabled for website', [
                'websiteId' => $websiteId
            ]);
            return false;
        }

        $postalCode = trim((string)($request->getDestPostcode() ?? ''));

        if ($postalCode === '') {
            $this->_logger->warning('[TmlShipping] No postal code yet');
            return false;
        }

        $countryId = strtoupper(trim((string)($request->getDestCountryId() ?? '')));

        if ($countryId !== 'AR') {
            $this->_logger->warning('[TmlShipping] Skipping rates - country not supported', [
                'country' => $countryId
            ]);
            return false;
        }

        $packageWeight = (float)($request->getPackageWeight() ?? 0.0);
        $grams = $this->weightCalc->fromPackageWeight($packageWeight, $storeId);

        $this->_logger->info('[TmlShipping] Calling rates API', [
            'websiteId' => $websiteId,
            'postalCode' => $postalCode,
            'grams' => $grams
        ]);

        $rate = $this->ratesClient->getRate($websiteId, $grams, $postalCode);
        if (!$rate) {
            $this->_logger->warning('[TmlShipping] No rate returned from API', [
                'websiteId' => $websiteId,
                'postalCode' => $postalCode,
                'grams' => $grams
            ]);
            return false;
        }

        $this->_logger->info('[TmlShipping] Rate received', [
            'serviceName' => $rate->serviceName,
            'price' => $rate->totalPrice
        ]);

        $result = $this->rateResultFactory->create();

        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle((string)($this->getConfigData('title') ?: 'TML'));
        $method->setMethod('tml');
        $method->setMethodTitle($this->resolveMethodTitle($rate));
        $price = max(0.0, $rate->totalPrice);
        $method->setPrice($price);
        $method->setCost($price);

        $result->append($method);

        return $result;
    }

    private function resolveMethodTitle(CarrierRate $rate): string
    {
        $parts = [];

        $parts[] = $rate->serviceName;

        if ($rate->description) {
            $parts[] = $rate->description;
        }

        $delivery = $this->resolveDeliveryWindow(
            $rate->minDeliveryDate,
            $rate->maxDeliveryDate
        );

        if ($delivery !== '') {
            $parts[] = $delivery;
        }

        return implode(' · ', $parts);
    }

    private function resolveDeliveryWindow(?string $min, ?string $max): string
    {
        $minDate = $this->formatDate($min);
        $maxDate = $this->formatDate($max);

        if (!$minDate && !$maxDate) {
            return '';
        }

        if ($minDate && $maxDate) {
            return "Entrega estimada: $minDate al $maxDate";
        }

        if ($minDate) {
            return "Entrega estimada desde: $minDate";
        }

        return "Entrega estimada hasta: $maxDate";
    }

    private function formatDate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        try {
            $dt = new \DateTime($date);
            return $dt->format('d/m/Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

}
