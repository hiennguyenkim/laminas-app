<?php

declare(strict_types=1);

namespace Library\Factory\Form;

use Library\Form\UserForm;
use Psr\Container\ContainerInterface;

class UserCreateFormFactory
{
    public function __invoke(ContainerInterface $container): UserForm
    {
        return new UserForm(true);
    }
}
