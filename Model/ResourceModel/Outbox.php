<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Outbox extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('molipa_tmlshipping_outbox', 'entity_id');
    }
}
