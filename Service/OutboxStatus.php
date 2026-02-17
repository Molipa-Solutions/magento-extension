<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

use Molipa\TmlShipping\Model\Outbox;
use Molipa\TmlShipping\Model\ResourceModel\Outbox as OutboxResource;

class OutboxStatus
{
    private const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600, 86400];

    /** @var OutboxResource */
    private $outboxResource;

    public function __construct(
        OutboxResource $outboxResource
    ) {
        $this->outboxResource = $outboxResource;
    }

    public function markSent(Outbox $o): void
    {
        $o->setData('status', 'sent');
        $o->setData('last_error', null);
        $o->setData('next_attempt_at', null);
        $this->outboxResource->save($o);
    }

    public function markFailed(Outbox $o, string $error): void
    {
        $attempts = (int)$o->getData('attempts');
        $attempts++;

        $delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];
        $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $delay . ' seconds')
            ->format('Y-m-d H:i:s');

        $o->setData('status', 'failed');
        $o->setData('attempts', $attempts);
        $o->setData('last_error', mb_substr($error, 0, 4000));
        $o->setData('next_attempt_at', $next);

        $this->outboxResource->save($o);
    }
}
