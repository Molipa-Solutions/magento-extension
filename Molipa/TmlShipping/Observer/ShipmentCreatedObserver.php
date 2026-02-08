<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Shipment;
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
    private const XML_PATH_WEIGHT_UNIT = 'general/locale/weight_unit';
    private const EVENT_TYPE = 'shipment_created';

    public function __construct(
        private readonly WebhookSender $webhookSender,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CountryFactory $countryFactory,
        private readonly RegionFactory $regionFactory,
        private readonly OutboxEnqueuer $outboxEnqueuer,
        private readonly OutboxStatus $outboxStatus,
    ) {}

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

            'products' => $this->mapItems($order),
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



    private function mapItems(Order $order): array
    {
        $products = [];
        $storeId = (int)$order->getStoreId();
        $unit = $this->getWeightUnit($storeId);

        foreach ($order->getAllVisibleItems() as $item) {
            $weightPerUnit = (float)($item->getWeight() ?? 0);
            $qty = (int)$item->getQtyOrdered();

            $safeQty = max($qty, 1);
            $grams = $this->toGrams($weightPerUnit, $unit) * $safeQty;

            $products[] = [
                'sku' => (string)$item->getSku(),
                'name' => (string)$item->getName(),
                'grams' => $grams,
                'quantity' => $safeQty,
                'price' => (float)$item->getPrice(),
            ];
        }

        return $products;
    }

    private function getWeightUnit(int $storeId): string
    {
        $unit = (string)$this->scopeConfig->getValue(
            self::XML_PATH_WEIGHT_UNIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $unit = strtolower(trim($unit));
        return $unit !== '' ? $unit : 'kgs';
    }

    private function toGrams(float $weight, string $unit): int
    {
        if ($weight <= 0) {
            return 0;
        }

        return match ($unit) {
            'kgs', 'kg' => (int)round($weight * 1000),
            'lbs', 'lb' => (int)round($weight * 453.59237),
            default => (int)round($weight * 1000),
        };
    }

}
