<?php

declare(strict_types=1);

namespace Library\Factory\View\Helper;

use Library\Session\AuthSessionContainer;
use Library\View\Helper\CurrentUserHelper;
use Psr\Container\ContainerInterface;

class CurrentUserHelperFactory
{
    public function __invoke(ContainerInterface $container): CurrentUserHelper
    {
        return new CurrentUserHelper($container->get(AuthSessionContainer::class));
    }
}
