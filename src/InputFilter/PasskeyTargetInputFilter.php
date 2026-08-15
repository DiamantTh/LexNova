<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\Digits;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

final class PasskeyTargetInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('user_id', [new StringTrim()], [
            new NotEmpty(),
            new Digits(),
            new StringLength(['max' => 20]),
        ]);
    }
}
