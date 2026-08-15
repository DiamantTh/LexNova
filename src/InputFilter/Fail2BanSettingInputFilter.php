<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\InArray;

final class Fail2BanSettingInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('mode', [new StringTrim()], [new InArray([
            'haystack' => ['config', 'enabled', 'disabled'],
            'strict' => InArray::COMPARE_STRICT,
            'messages' => [InArray::NOT_IN_ARRAY => 'Invalid Fail2ban setting.'],
        ])]);
    }
}
