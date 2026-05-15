<?php

declare(strict_types=1);

namespace Library\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;
use Laminas\InputFilter\InputFilter;

/**
 * @psalm-suppress MissingTemplateParam
 */
class LoginForm extends Form
{
    public function __construct()
    {
        parent::__construct('login_form');
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
            'attributes' => ['class' => 'form-control', 'required' => true, 'autofocus' => true],
        ]);
        $this->add([
            'name'       => 'password',
            'type'       => Element\Password::class,
            'options'    => ['label' => 'Mật khẩu'],
            'attributes' => ['class' => 'form-control', 'required' => true],
        ]);
        $this->add([
            'name' => 'csrf',
            'type' => Element\Csrf::class,
        ]);
        $this->add([
            'name'       => 'submit',
            'type'       => Element\Submit::class,
            'attributes' => ['value' => 'Đăng nhập', 'class' => 'btn btn-primary w-100'],
        ]);
    }

    private function buildInputFilter(): InputFilter
    {
        $filter = new InputFilter();
        $filter->add([
            'name'     => 'username',
            'required' => true,
            'filters'  => [['name' => \Laminas\Filter\StringTrim::class]],
        ]);
        $filter->add([
            'name'     => 'password',
            'required' => true,
        ]);
        $filter->add([
            'name'     => 'csrf',
            'required' => true,
        ]);
        return $filter;
    }
}
