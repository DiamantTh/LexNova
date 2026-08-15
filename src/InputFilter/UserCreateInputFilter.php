<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\InArray;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;

final class UserCreateInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('username', [new StringTrim()], [
            new StringLength(['min' => 3, 'max' => 100]),
            new Regex([
                'pattern' => '/^[a-zA-Z0-9_.@+-]+$/D',
                'messages' => [
                    Regex::NOT_MATCH => 'Username may contain letters, digits, ., _, @, + and -.',
                ],
            ]),
        ]);
        $this->validateField('role', [new StringTrim()], [new InArray([
            'haystack' => ['admin'],
            'strict' => InArray::COMPARE_STRICT,
            'messages' => [InArray::NOT_IN_ARRAY => 'Invalid role.'],
        ])]);
        $this->validateField('authentication', [new StringTrim()], [new InArray([
            'haystack' => ['password', 'passkey'],
            'strict' => InArray::COMPARE_STRICT,
            'messages' => [InArray::NOT_IN_ARRAY => 'Invalid authentication method.'],
        ])]);
        $this->validateField('password', [], [new StringLength(['max' => 256])], true);
        $this->validateField('password_confirm', [], [new StringLength(['max' => 256])], true);

        if ($this->value('authentication') === 'password' && $this->value('password') === '') {
            $this->addMessage('password', 'required', 'Password is required.');
        }
        if ($this->value('password') !== $this->value('password_confirm')) {
            $this->addMessage('password_confirm', 'mismatch', 'Passwords do not match.');
        }
    }
}
