<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Molipa\TmlShipping\Service\WebhookSender;
use Molipa\TmlShipping\Model\Config;

class InvoicePaidObserver implements ObserverInterface
{
    public function __construct(
        private readonly WebhookSender $webhookSender,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        $this->logger->info('Event data keys: ' . implode(',', array_keys($observer->getEvent()->getData())));

        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        $invoice = $observer->getEvent()->getData('invoice');
        if (!$invoice) {
            return;
        }

        $order = $invoice->getOrder();
        if (!$order) {
            return;
        }

        $websiteId = (int)$order->getStore()->getWebsiteId();

        if (!$this->config->isEnabledForWebsite($websiteId)) {
            return;
        }

        // EventId estable para idempotencia: invoice entity id (o increment id)
        $eventId = (string)($invoice->getIncrementId() ?: $invoice->getEntityId() ?: '');

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
            'observations' => null,

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

        try {
            $this->webhookSender->sendOrdersPaid($payload, $eventId, $websiteId);
        } catch (\Throwable $e) {
            $this->logger->error('[TmlShipping] Webhook send failed', ['e' => $e]);
        }
    }

    private function mapShippingAddress(\Magento\Sales\Model\Order $order): array
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
            'state' => (string)$addr->getRegionCode(),
            'postalCode' => $addr->getPostcode(),
            'country' => (string)$addr->getCountryId(),
        ];
    }

    private function mapItems(\Magento\Sales\Model\Order $order): array
    {
        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $products[] = [
                'sku' => (string)$item->getSku(),
                'name' => (string)$item->getName(),
                'grams' => 1,
                'quantity' => (int)$item->getQtyOrdered(),
                'price' => (float)$item->getPrice(),
            ];
        }
        return $products;
    }
}
