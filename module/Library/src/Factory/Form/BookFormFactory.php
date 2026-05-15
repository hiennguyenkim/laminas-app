<?php

declare(strict_types=1);

namespace Library\Factory\Form;

use Library\Form\BookForm;
use Psr\Container\ContainerInterface;

class BookFormFactory
{
    /**
     * @psalm-suppress PossiblyUnusedParam
     */
    public function __invoke(ContainerInterface $container): BookForm
    {
        return new BookForm();
    }
}
