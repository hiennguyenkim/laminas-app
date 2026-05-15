<?php

declare(strict_types=1);

namespace Library\Factory\Form;

use Library\Form\BorrowForm;
use Psr\Container\ContainerInterface;

class BorrowFormFactory
{
    /**
     * @psalm-suppress PossiblyUnusedParam
     */
    public function __invoke(ContainerInterface $container): BorrowForm
    {
        return new BorrowForm();
    }
}
