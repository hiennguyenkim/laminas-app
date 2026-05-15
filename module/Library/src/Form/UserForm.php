<?php

declare(strict_types=1);

namespace Library\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\Hostname;
use Laminas\Validator\Identical;

/**
 * @psalm-suppress MissingTemplateParam
 */
class UserForm extends Form
{
    public function __construct(bool $requirePassword = true)
    {
        parent::__construct('user_form');
        $this->setAttribute('method', 'POST');
        $this->buildElements();
        $this->setInputFilter($this->buildInputFilter($requirePassword));
    }

    private function buildElements(): void
    {
        $this->add(['name' => 'id', 'type' => Element\Hidden::class]);

        $this->add([
            'name'       => 'full_name',
            'type'       => Element\Text::class,
            'options'    => ['label' => 'Họ và tên'],
            'attributes' => ['class' => 'form-control', 'maxlength' => 100, 'required' => true],
        ]);

        $this->add([
            'name'       => 'username',
            'type'       => Element\Text::class,
            'options'    => ['label' => 'Tên đăng nhập'],
            'attributes' => ['class' => 'form-control', 'maxlength' => 50, 'required' => true],
        ]);

        $this->add([
            'name'       => 'email',
            'type'       => Element\Email::class,
            'options'    => ['label' => 'Email'],
            'attributes' => ['class' => 'form-control', 'maxlength' => 100, 'required' => true],
        ]);

        $this->add([
            'name'       => 'role',
            'type'       => Element\Select::class,
            'options'    => [
                'label'         => 'Vai trò',
                'value_options' => [
                    'admin'   => 'Quản trị viên',
                    'student' => 'Sinh viên',
                ],
            ],
            'attributes' => ['class' => 'form-select', 'required' => true],
        ]);

        $this->add([
            'name'       => 'password',
            'type'       => Element\Password::class,
            'options'    => ['label' => 'Mật khẩu'],
            'attributes' => ['class' => 'form-control'],
        ]);

        $this->add([
            'name'       => 'password_confirm',
            'type'       => Element\Password::class,
            'options'    => ['label' => 'Xác nhận mật khẩu'],
            'attributes' => ['class' => 'form-control'],
        ]);

        $this->add([
            'name' => 'csrf',
            'type' => Element\Csrf::class,
        ]);

        $this->add([
            'name'       => 'submit',
            'type'       => Element\Submit::class,
            'attributes' => ['value' => 'Lưu tài khoản', 'class' => 'btn btn-primary'],
        ]);
    }

    private function buildInputFilter(bool $requirePassword): InputFilter
    {
        $filter = new InputFilter();

        $filter->add(['name' => 'id', 'required' => false]);
        $filter->add([
            'name'       => 'full_name',
            'required'   => true,
            'filters'    => [['name' => \Laminas\Filter\StringTrim::class]],
            'validators' => [[
                'name'    => \Laminas\Validator\StringLength::class,
                'options' => ['min' => 3, 'max' => 100],
            ]],
        ]);
        $filter->add([
            'name'       => 'username',
            'required'   => true,
            'filters'    => [['name' => \Laminas\Filter\StringTrim::class]],
            'validators' => [[
                'name'    => \Laminas\Validator\StringLength::class,
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
            'name'       => 'role',
            'required'   => true,
            'validators' => [[
                'name'    => \Laminas\Validator\InArray::class,
                'options' => ['haystack' => ['admin', 'student']],
            ]],
        ]);
        $filter->add([
            'name'              => 'password',
            'required'          => $requirePassword,
            'allow_empty'       => ! $requirePassword,
            'continue_if_empty' => false,
            'validators'        => [[
                'name'    => \Laminas\Validator\StringLength::class,
                'options' => ['min' => 8, 'max' => 255],
            ]],
        ]);
        $filter->add([
            'name'              => 'password_confirm',
            'required'          => $requirePassword,
            'allow_empty'       => ! $requirePassword,
            'continue_if_empty' => false,
            'validators'        => [[
                'name'    => Identical::class,
                'options' => ['token' => 'password'],
            ]],
        ]);
        $filter->add(['name' => 'csrf', 'required' => true]);

        return $filter;
    }
}
