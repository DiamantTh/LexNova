<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\Regex;

final class TotpVerificationInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('code', [new StringTrim()], [new Regex([
            'pattern' => '/^\d{6}$/D',
            'messages' => [Regex::NOT_MATCH => 'The authentication code must contain exactly six digits.'],
        ])]);
    }
}
