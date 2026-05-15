<?php

declare(strict_types=1);

namespace Library\Factory\Form;

use Library\Form\RegisterForm;
use Psr\Container\ContainerInterface;

class RegisterFormFactory
{
    public function __invoke(ContainerInterface $container): RegisterForm
    {
        return new RegisterForm();
    }
}
