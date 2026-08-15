<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\Callback as CallbackFilter;
use Laminas\Filter\StringTrim;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

final class EntityInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('name', [new StringTrim()], [new NotEmpty(), new StringLength(['max' => 255])]);
        $this->validateField('contact_data', [
            new CallbackFilter(
                static fn (string $value): string => str_replace(["\r\n", "\r"], "\n", $value),
            ),
            new StringTrim(),
        ], [new NotEmpty(), new StringLength(['max' => 65535])]);
    }
}
