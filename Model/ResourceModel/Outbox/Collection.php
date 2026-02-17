<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Model\ResourceModel\Outbox;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Molipa\TmlShipping\Model\Outbox as OutboxModel;
use Molipa\TmlShipping\Model\ResourceModel\Outbox as OutboxResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(OutboxModel::class, OutboxResource::class);
    }
}
