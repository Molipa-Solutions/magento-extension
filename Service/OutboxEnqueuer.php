<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Molipa\TmlShipping\Model\Outbox;
use Molipa\TmlShipping\Model\OutboxFactory;
use Molipa\TmlShipping\Model\ResourceModel\Outbox as OutboxResource;
use Molipa\TmlShipping\Model\ResourceModel\Outbox\CollectionFactory as OutboxCollectionFactory;

class OutboxEnqueuer
{
    /** @var OutboxFactory */
    private $outboxFactory;

    /** @var OutboxResource */
    private $outboxResource;

    /** @var OutboxCollectionFactory */
    private $collectionFactory;

    public function __construct(
        OutboxFactory $outboxFactory,
        OutboxResource $outboxResource,
        OutboxCollectionFactory $collectionFactory
    ) {
        $this->outboxFactory = $outboxFactory;
        $this->outboxResource = $outboxResource;
        $this->collectionFactory = $collectionFactory;
    }

    public function getOrCreate(
        string $eventType,
        int $shipmentId,
        int $websiteId,
        string $eventId,
        string $payloadJson
    ): Outbox {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('event_type', $eventType);
        $collection->addFieldToFilter('shipment_id', $shipmentId);
        $collection->addFieldToFilter('website_id', $websiteId);
        $collection->setPageSize(1);

        $existing = $collection->getFirstItem();
        if ($existing && $existing->getId()) {

            $existing->setData('payload_json', $payloadJson);
            if ((string)$existing->getData('status') !== 'sent') {
                $existing->setData('status', 'pending');
            }
            $this->outboxResource->save($existing);
            return $existing;
        }

        $model = $this->outboxFactory->create();
        $model->setData([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'shipment_id' => $shipmentId,
            'website_id' => $websiteId,
            'payload_json' => $payloadJson,
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'next_attempt_at' => null,
        ]);

        $this->outboxResource->save($model);
        return $model;
    }
}
