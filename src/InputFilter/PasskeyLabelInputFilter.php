<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

final class PasskeyLabelInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('label', [new StringTrim()], [
            new NotEmpty(),
            new StringLength(['max' => 100]),
        ]);
    }
}
