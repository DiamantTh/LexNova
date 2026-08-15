<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;

final class LoginInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('username', [new StringTrim()], [
            new NotEmpty(),
            new StringLength(['max' => 100]),
            new Regex([
                'pattern' => '/^[a-zA-Z0-9_.@+-]+$/D',
                'messages' => [Regex::NOT_MATCH => 'Invalid username or password.'],
            ]),
        ]);
        $this->validateField('password', [], [
            new NotEmpty(),
            new StringLength(['max' => 256]),
        ]);
    }
}
