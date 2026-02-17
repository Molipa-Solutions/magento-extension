<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Model;

use Magento\Framework\Model\AbstractModel;
use Molipa\TmlShipping\Model\ResourceModel\Outbox as OutboxResource;

class Outbox extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(OutboxResource::class);
    }
}
