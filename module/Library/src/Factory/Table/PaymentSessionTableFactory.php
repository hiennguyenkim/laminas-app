<?php

declare(strict_types=1);

namespace Library\Factory\Table;

use Library\Model\Entity\PaymentSession;
use Library\Model\Table\PaymentSessionTable;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Psr\Container\ContainerInterface;

class PaymentSessionTableFactory
{
    public function __invoke(ContainerInterface $container): PaymentSessionTable
    {
        $adapter            = $container->get(AdapterInterface::class);
        $resultSetPrototype = new ResultSet();
        /** @var \ArrayObject $prototype */
        $prototype = new PaymentSession();
        $resultSetPrototype->setArrayObjectPrototype($prototype);
        $tableGateway       = new TableGateway('payment_sessions', $adapter, null, $resultSetPrototype);
        return new PaymentSessionTable($tableGateway);
    }
}
