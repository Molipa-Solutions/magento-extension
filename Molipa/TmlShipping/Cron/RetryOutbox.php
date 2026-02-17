<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Cron;

use Molipa\TmlShipping\Model\ResourceModel\Outbox\CollectionFactory as OutboxCollectionFactory;
use Molipa\TmlShipping\Service\OutboxStatus;
use Molipa\TmlShipping\Service\WebhookSender;
use Psr\Log\LoggerInterface;

class RetryOutbox
{
    private const MAX_ATTEMPTS = 10;
    private const EVENT_TYPE = 'shipment_created';

    /** @var OutboxCollectionFactory */
    private $collectionFactory;
    /** @var WebhookSender */
    private $webhookSender;
    /** @var OutboxStatus */
    private $status;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        OutboxCollectionFactory $collectionFactory,
        WebhookSender $webhookSender,
        OutboxStatus $status,
        LoggerInterface $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->webhookSender = $webhookSender;
        $this->status = $status;
        $this->logger = $logger;
    }

    public function run(int $limit = 50, ?array &$report = null): int
    {
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('event_type', self::EVENT_TYPE);
        $collection->addFieldToFilter('status', ['in' => ['pending', 'failed']]);
        $collection->addFieldToFilter('attempts', ['lt' => self::MAX_ATTEMPTS]);
        $collection->getSelect()->where(
            '(next_attempt_at IS NULL) OR (next_attempt_at <= ?)',
            $nowUtc
        );
        $collection->setPageSize($limit);

        $total = (int)$collection->getSize();

        $sent = 0;
        $failed = 0;
        $failures = [];

        $this->logger->info('[TML] RetryOutbox run start', [
            'now' => $nowUtc,
            'limit' => $limit,
            'candidates' => $total,
        ]);

        foreach ($collection as $o) {
            $eventId   = (string)$o->getData('event_id');
            $websiteId = (int)$o->getData('website_id');
            $attemptsBefore = (int)$o->getData('attempts');

            try {
                $payload = json_decode(
                    (string)$o->getData('payload_json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $this->webhookSender->sendOrders($payload, $eventId, $websiteId);
                $this->status->markSent($o);

                $sent++;

                $this->logger->info('[TML] RetryOutbox sent', [
                    'event_id' => $eventId,
                    'website_id' => $websiteId,
                    'attempts_before' => $attemptsBefore,
                ]);
            } catch (\Throwable $e) {
                $failed++;

                $this->status->markFailed($o, $e->getMessage());

                if (count($failures) < 10) {
                    $failures[] = [
                        'event_id' => $eventId,
                        'website_id' => $websiteId,
                        'attempts_before' => $attemptsBefore,
                        'error' => $e->getMessage(),
                    ];
                }

                $this->logger->error('[TML] RetryOutbox failed', [
                    'event_id' => $eventId,
                    'website_id' => $websiteId,
                    'attempts_before' => $attemptsBefore,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $report = [
            'now' => $nowUtc,
            'limit' => $limit,
            'candidates' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'sample_failures' => $failures,
        ];

        $this->logger->info('[TML] RetryOutbox run finished', $report);

        return $sent;
    }

    /** Cron entrypoint */
    public function execute(): void
    {
        $this->run(50);
    }
}
