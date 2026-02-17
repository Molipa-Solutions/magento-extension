<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Shipment;
use Molipa\TmlShipping\Service\WeightInGramsCalculator;
use Psr\Log\LoggerInterface;
use Molipa\TmlShipping\Service\WebhookSender;
use Molipa\TmlShipping\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\RegionFactory;
use Molipa\TmlShipping\Service\OutboxEnqueuer;
use Molipa\TmlShipping\Service\OutboxStatus;


class ShipmentCreatedObserver implements ObserverInterface
{
    private const EVENT_TYPE = 'shipment_created';

    /** @var WebhookSender */
    private $webhookSender;
    /** @var Config */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var ScopeConfigInterface */
    private $scopeConfig;
    /** @var CountryFactory */
    private $countryFactory;
    /** @var RegionFactory */
    private $regionFactory;
    /** @var OutboxEnqueuer */
    private $outboxEnqueuer;
    /** @var WeightInGramsCalculator */
    private $weightCalc;
    /** @var OutboxStatus */
    private $outboxStatus;

    public function __construct(
        WebhookSender $webhookSender,
        Config $config,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        CountryFactory $countryFactory,
        RegionFactory $regionFactory,
        OutboxEnqueuer $outboxEnqueuer,
        WeightInGramsCalculator $weightCalc,
        OutboxStatus $outboxStatus
    ) {
        $this->webhookSender = $webhookSender;
        $this->config = $config;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->countryFactory = $countryFactory;
        $this->regionFactory = $regionFactory;
        $this->outboxEnqueuer = $outboxEnqueuer;
        $this->weightCalc = $weightCalc;
        $this->outboxStatus = $outboxStatus;
    }

    public function execute(Observer $observer): void
    {
        $this->logger->info('Event data keys: ' . implode(',', array_keys($observer->getEvent()->getData())));

        /** @var Shipment|null $shipment */
        $shipment = $observer->getEvent()->getData('shipment');

        // fallback
        if (!$shipment && method_exists($observer->getEvent(), 'getShipment')) {
            $shipment = $observer->getEvent()->getShipment();
        }

        if (!$shipment) {
            return;
        }

        $order = $shipment->getOrder();
        if (!$order) {
            return;
        }

        $websiteId = (int)$order->getStore()->getWebsiteId();

        if (!$this->config->isEnabledForWebsite($websiteId)) {
            return;
        }

        $shippingMethod = (string)($order->getShippingMethod() ?? '');
        if ($shippingMethod !== 'tml_tml') {
            $this->logger->info('[TmlShipping] Skipping shipment webhook - not TML shipping method', [
                'shippingMethod' => $shippingMethod,
                'orderId' => $order->getIncrementId()
            ]);
            return;
        }


        $eventId = 'shipment:' . $shipment->getEntityId();

        $orderDate = null;
        try {
            $dt = new \DateTime($order->getCreatedAt());
            $orderDate = $dt->format('Y-m-d\TH:i:s');
        } catch (\Throwable $e) {
            $orderDate = null;
        }

        $payload = [
            'orderId' => $order->getIncrementId(),
            'orderDate' => $orderDate,
            'currency' => $order->getOrderCurrencyCode(),
            'observations' => $order->getCustomerNote(),

            'customer' => [
                'name' => (string)$order->getCustomerFirstname(),
                'lastName' => (string)$order->getCustomerLastname(),
                'email' => (string)$order->getCustomerEmail(),
                'phone' => (string) (
                $order->getBillingAddress()
                    ? $order->getBillingAddress()->getTelephone()
                    : ''
                ),
            ],

            'shippingAddress' => $this->mapShippingAddress($order),

            'products' => $this->mapItems($shipment),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $outbox = $this->outboxEnqueuer->getOrCreate(
            self::EVENT_TYPE,
            (int)$shipment->getEntityId(),
            $websiteId,
            $eventId,
            $payloadJson
        );

        try {
            $this->webhookSender->sendOrders($payload, $eventId, $websiteId);
            $this->outboxStatus->markSent($outbox);
        } catch (\Throwable $e) {
            $this->outboxStatus->markFailed($outbox, $e->getMessage());
            $this->logger->error('[TmlShipping] Webhook send failed', ['e' => $e]);
        }
    }

    private function mapShippingAddress(Order $order): array
    {
        $addr = $order->getShippingAddress() ?: $order->getBillingAddress();
        if (!$addr) {
            return [
                'street' => '',
                'number' => null,
                'city' => null,
                'state' => '',
                'postalCode' => null,
                'country' => '',
            ];
        }

        $streetLines = $addr->getStreet() ?: [];
        $street = is_array($streetLines) ? ($streetLines[0] ?? '') : (string)$streetLines;

        return [
            'street' => $street,
            'number' => null,
            'city' => $addr->getCity(),
            'state' => $this->getStateName($addr),
            'postalCode' => $addr->getPostcode(),
            'country' => $this->getCountryName($addr),
        ];
    }

    private function getStateName(Address $addr): string
    {
        $state = trim($addr->getRegion() ?? '');
        if ($state !== '') {
            return $state;
        }

        $regionCode = trim($addr->getRegionCode() ?? '');
        $countryId  = trim($addr->getCountryId() ?? '');

        if ($regionCode !== '' && $countryId !== '') {
            $region = $this->regionFactory
                ->create()
                ->loadByCode($regionCode, $countryId);

            $name = trim($region->getName());
            if ($name !== '') {
                return $name;
            }
        }

        return $regionCode;
    }

    private function getCountryName(Address $addr): string
    {
        $countryId = trim($addr->getCountryId() ?? '');
        if ($countryId === '') {
            return '';
        }

        $country = $this->countryFactory
            ->create()
            ->loadByCode($countryId);

        $name = trim($country->getName());
        return $name !== '' ? $name : $countryId;
    }



    private function mapItems(Shipment $shipment): array
    {
        $products = [];
        $order = $shipment->getOrder();
        $storeId = (int)$order->getStoreId();

        foreach ($shipment->getAllItems() as $sItem) {
            $orderItem = $sItem->getOrderItem();
            if (!$orderItem) continue;

            $weightPerUnit = (float)($orderItem->getWeight() ?? 0);
            $qty = (float)($sItem->getQty() ?? 0);

            if ($qty <= 0) continue;

            $gramsPerUnit = $this->weightCalc->fromPackageWeight($weightPerUnit, $storeId);
            $grams = $gramsPerUnit * $qty;

            $products[] = [
                'sku' => (string)$orderItem->getSku(),
                'name' => (string)$orderItem->getName(),
                'grams' => $grams,
                'quantity' => $qty,
                'price' => (float)$orderItem->getPrice(),
            ];
        }

        return $products;
    }

}
