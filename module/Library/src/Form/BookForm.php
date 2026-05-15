<?php

declare(strict_types=1);

namespace Library\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator;

/**
 * @psalm-suppress MissingTemplateParam
 */
class BookForm extends Form
{
    public function __construct()
    {
        parent::__construct('book_form');
        $this->setAttribute('method', 'POST');
        $this->buildElements();
        $this->setInputFilter($this->buildInputFilter());
    }

    private function buildElements(): void
    {
        $this->add(['name' => 'id', 'type' => Element\Hidden::class]);

        $this->add([
            'name'       => 'title',
            'type'       => Element\Text::class,
            'options'    => ['label' => 'Tên sách'],
            'attributes' => ['class' => 'form-control', 'required' => true, 'maxlength' => 255],
        ]);

        $this->add([
            'name'       => 'author',
            'type'       => Element\Text::class,
            'options'    => ['label' => 'Tác giả'],
            'attributes' => ['class' => 'form-control', 'required' => true],
        ]);

        $this->add([
            'name'       => 'isbn',
            'type'       => Element\Text::class,
            'options'    => ['label' => 'ISBN'],
            'attributes' => ['class' => 'form-control', 'placeholder' => '978-xxx-xxx-xx-x'],
        ]);

        $this->add([
            'name'       => 'category',
            'type'       => Element\Select::class,
            'options'    => [
                'label'         => 'Thể loại',
                'value_options' => [
                    'Công nghệ' => 'Công nghệ',
                    'Toán học'  => 'Toán học',
                    'Khoa học'  => 'Khoa học',
                    'Lý luận'   => 'Lý luận',
                    'Văn học'   => 'Văn học',
                    'Ngoại ngữ' => 'Ngoại ngữ',
                    'Khác'      => 'Khác',
                ],
            ],
            'attributes' => ['class' => 'form-select'],
        ]);

        $this->add([
            'name'       => 'quantity',
            'type'       => Element\Number::class,
            'options'    => ['label' => 'Số lượng'],
            'attributes' => ['class' => 'form-control', 'min' => 1, 'max' => 999],
        ]);

        $this->add([
            'name'       => 'status',
            'type'       => Element\Select::class,
            'options'    => [
                'label'         => 'Trạng thái',
                'value_options' => [
                    'available'   => 'Khả dụng',
                    'unavailable' => 'Không khả dụng',
                ],
            ],
            'attributes' => ['class' => 'form-select'],
        ]);

        $this->add([
            'name' => 'csrf',
            'type' => Element\Csrf::class,
        ]);
        $this->add([
            'name'       => 'submit',
            'type'       => Element\Submit::class,
            'attributes' => ['value' => 'Lưu sách', 'class' => 'btn btn-primary'],
        ]);
    }

    /**
     * @psalm-suppress DeprecatedClass
     */
    private function buildInputFilter(): InputFilter
    {
        $filter = new InputFilter();

        $filter->add(['name' => 'id',    'required' => false]);
        $filter->add(['name' => 'title', 'required' => true,
            'filters'    => [['name' => \Laminas\Filter\StringTrim::class]],
            'validators' => [[
                'name' => \Laminas\Validator\StringLength::class,
                'options' => ['min' => 2, 'max' => 255],
            ]],
        ]);
        $filter->add(['name' => 'author', 'required' => true,
            'filters'    => [['name' => \Laminas\Filter\StringTrim::class]],
        ]);
        $filter->add(['name' => 'isbn', 'required' => false,
            'filters'    => [['name' => \Laminas\Filter\StringTrim::class]],
            'validators' => [
                ['name' => \Laminas\Validator\StringLength::class, 'options' => ['max' => 30]],
            ],
        ]);
        $filter->add(['name' => 'category', 'required' => true]);
        $filter->add(['name' => 'quantity',  'required' => true,
            'validators' => [
                ['name' => \Laminas\Validator\Digits::class],
                ['name' => \Laminas\Validator\GreaterThan::class, 'options' => ['min' => 0]],
                ['name' => \Laminas\Validator\LessThan::class, 'options' => ['max' => 1000]],
            ],
        ]);
        $filter->add(['name' => 'status', 'required' => true]);
        $filter->add(['name' => 'csrf', 'required' => true]);

        return $filter;
    }
}
