<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\Callback as CallbackFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/** @extends InputFilter<array{name: string, contact_data: string}> */
final class EntityInputFilter extends InputFilter
{
    public function __construct()
    {
        $name = new Input('name');
        $name->getFilterChain()->attach(new StringTrim());
        $name->getValidatorChain()
            ->attach(new NotEmpty())
            ->attach(new StringLength(['max' => 255]));
        $this->add($name);

        $contact = new Input('contact_data');
        $contact->getFilterChain()
            ->attach(new CallbackFilter(
                static fn (string $value): string => str_replace(["\r\n", "\r"], "\n", $value),
            ))
            ->attach(new StringTrim());
        $contact->getValidatorChain()
            ->attach(new NotEmpty())
            ->attach(new StringLength(['max' => 65535]));
        $this->add($contact);
    }
}
