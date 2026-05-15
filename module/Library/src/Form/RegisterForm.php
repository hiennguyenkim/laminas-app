<?php

declare(strict_types=1);

namespace Library\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\Hostname;
use Laminas\Validator\Identical;
use Laminas\Validator\StringLength;

/**
 * @psalm-suppress MissingTemplateParam
 */
class RegisterForm extends Form
{
    public function __construct()
    {
        parent::__construct('register_form');
        $this->setAttribute('method', 'POST');
        $this->buildElements();
        $this->setInputFilter($this->buildInputFilter());
    }

    private function buildElements(): void
    {
        $this->add([
            'name'       => 'username',
            'type'       => Element\Text::class,
            'options'    => ['label' => 'Tên đăng nhập'],
            'attributes' => ['class' => 'form-control', 'required' => true, 'maxlength' => 50],
        ]);

        $this->add([
            'name'       => 'email',
            'type'       => Element\Email::class,
            'options'    => ['label' => 'Email'],
            'attributes' => ['class' => 'form-control', 'required' => true, 'maxlength' => 100],
        ]);

        $this->add([
            'name'       => 'password',
            'type'       => Element\Password::class,
            'options'    => ['label' => 'Mật khẩu'],
            'attributes' => ['class' => 'form-control', 'required' => true],
        ]);

        $this->add([
            'name'       => 'password_confirm',
            'type'       => Element\Password::class,
            'options'    => ['label' => 'Xác nhận mật khẩu'],
            'attributes' => ['class' => 'form-control', 'required' => true],
        ]);

        $this->add([
            'name' => 'csrf',
            'type' => Element\Csrf::class,
        ]);
    }

    private function buildInputFilter(): InputFilter
    {
        $filter = new InputFilter();

        $filter->add([
            'name'       => 'username',
            'required'   => true,
            'filters'    => [['name' => \Laminas\Filter\StringTrim::class]],
            'validators' => [[
                'name'    => StringLength::class,
                'options' => ['min' => 3, 'max' => 50],
            ]],
        ]);

        $filter->add([
            'name'       => 'email',
            'required'   => true,
            'filters'    => [['name' => \Laminas\Filter\StringTrim::class]],
            'validators' => [[
                'name'    => EmailAddress::class,
                'options' => [
                    'allow' => Hostname::ALLOW_DNS | Hostname::ALLOW_LOCAL,
                ],
            ]],
        ]);

        $filter->add([
            'name'       => 'password',
            'required'   => true,
            'validators' => [[
                'name'    => StringLength::class,
                'options' => ['min' => 8, 'max' => 255],
            ]],
        ]);

        $filter->add([
            'name'       => 'password_confirm',
            'required'   => true,
            'validators' => [[
                'name'    => Identical::class,
                'options' => ['token' => 'password'],
            ]],
        ]);

        $filter->add([
            'name'     => 'csrf',
            'required' => true,
        ]);

        return $filter;
    }
}
