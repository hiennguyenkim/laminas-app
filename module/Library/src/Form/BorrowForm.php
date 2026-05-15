<?php

declare(strict_types=1);

namespace Library\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;
use Laminas\InputFilter\InputFilter;

/**
 * @psalm-suppress MissingTemplateParam
 */
class BorrowForm extends Form
{
    /**
     * @param array<array-key, mixed> $bookOptions
     * @param array<array-key, mixed> $userOptions
     */
    public function __construct(array $bookOptions = [], array $userOptions = [])
    {
        parent::__construct('borrow_form');
        $this->setAttribute('method', 'POST');
        $this->buildElements();
        $this->setSelectionOptions($bookOptions, $userOptions);
        $this->setInputFilter($this->buildInputFilter());
    }

    /**
     * @param array<array-key, mixed> $bookOptions
     * @param array<array-key, mixed> $userOptions
     * @psalm-suppress MixedAssignment
     */
    public function setSelectionOptions(array $bookOptions, array $userOptions): void
    {
        $bookElement = $this->get('book_id');
        $userElement = $this->get('user_id');

        $normalizedBookOptions = [];
        foreach ($bookOptions as $key => $value) {
            $normalizedBookOptions[(string) $key] = (string) $value;
        }

        $normalizedUserOptions = [];
        foreach ($userOptions as $key => $value) {
            $normalizedUserOptions[(string) $key] = (string) $value;
        }

        if ($bookElement instanceof Element\Select) {
            $bookElement->setValueOptions($normalizedBookOptions);
        }

        if ($userElement instanceof Element\Select) {
            $userElement->setValueOptions($normalizedUserOptions);
        }
    }

    private function buildElements(): void
    {
        $this->add([
            'name'       => 'book_id',
            'type'       => Element\Select::class,
            'options'    => ['label' => 'Sách', 'value_options' => [], 'empty_option' => '-- Chọn sách --'],
            'attributes' => ['class' => 'form-select', 'required' => true],
        ]);
        $this->add([
            'name'       => 'user_id',
            'type'       => Element\Select::class,
            'options'    => ['label' => 'Sinh viên', 'value_options' => [], 'empty_option' => '-- Chọn sinh viên --'],
            'attributes' => ['class' => 'form-select', 'required' => true],
        ]);
        $this->add([
            'name'       => 'borrow_date',
            'type'       => Element\Date::class,
            'options'    => ['label' => 'Ngày mượn'],
            'attributes' => ['class' => 'form-control', 'required' => true, 'value' => date('Y-m-d')],
        ]);
        $this->add([
            'name'       => 'return_date',
            'type'       => Element\Date::class,
            'options'    => ['label' => 'Hạn trả'],
            'attributes' => [
                'class' => 'form-control',
                'required' => true,
                'value' => date('Y-m-d', strtotime('+14 days')),
            ],
        ]);
        $this->add([
            'name' => 'csrf',
            'type' => Element\Csrf::class,
        ]);
        $this->add([
            'name'       => 'submit',
            'type'       => Element\Submit::class,
            'attributes' => ['value' => 'Xác nhận mượn', 'class' => 'btn btn-success'],
        ]);
    }

    private function buildInputFilter(): InputFilter
    {
        $filter = new InputFilter();
        $filter->add(['name' => 'book_id',     'required' => true]);
        $filter->add(['name' => 'user_id',     'required' => true]);
        $filter->add([
            'name'       => 'borrow_date',
            'required'   => true,
            'validators' => [['name' => \Laminas\Validator\Date::class, 'options' => ['format' => 'Y-m-d']]],
        ]);
        $filter->add([
            'name'       => 'return_date',
            'required'   => true,
            'validators' => [['name' => \Laminas\Validator\Date::class, 'options' => ['format' => 'Y-m-d']]],
        ]);
        $filter->add(['name' => 'csrf', 'required' => true]);
        return $filter;
    }
}
