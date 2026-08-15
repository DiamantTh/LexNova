<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\InArray;
use Laminas\Validator\StringLength;

final class UserUpdateInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('role', [new StringTrim()], [new InArray([
            'haystack' => ['admin'],
            'strict' => InArray::COMPARE_STRICT,
            'messages' => [InArray::NOT_IN_ARRAY => 'Invalid role.'],
        ])]);
        $this->validateField('new_password', [], [new StringLength(['max' => 256])], true);
        $this->validateField('password_login_enabled', [new StringTrim()], [new InArray([
            'haystack' => ['0', '1'],
            'strict' => InArray::COMPARE_STRICT,
            'messages' => [InArray::NOT_IN_ARRAY => 'Invalid password login setting.'],
        ])]);
    }
}
