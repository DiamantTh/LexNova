<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\Digits;
use Laminas\Validator\IsJsonString;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

final class PasskeyCredentialInputFilter extends AbstractInputFilter
{
    public function __construct(private readonly bool $registration)
    {
    }

    protected function validate(): void
    {
        $this->validateField('credential', [], [
            new NotEmpty(),
            new StringLength(['max' => 131072]),
            new IsJsonString(),
        ]);
        $this->validateField('label', [new StringTrim()], $this->registration ? [
            new NotEmpty(),
            new StringLength(['max' => 100]),
        ] : [new StringLength(['max' => 100])], !$this->registration);
        $this->validateField('user_id', [new StringTrim()], [new StringLength(['max' => 20])], true);
        if ($this->value('user_id') !== '' && !(new Digits())->isValid($this->value('user_id'))) {
            $this->addMessage('user_id', 'digits', 'Invalid user ID.');
        }
    }
}
