<?php

declare(strict_types=1);

namespace Library\Factory\Form;

use Library\Form\LoginForm;
use Psr\Container\ContainerInterface;

class LoginFormFactory
{
    public function __invoke(ContainerInterface $container): LoginForm
    {
        return new LoginForm();
    }
}
