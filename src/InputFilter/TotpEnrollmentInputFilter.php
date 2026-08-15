<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

final class TotpEnrollmentInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('code', [new StringTrim()], []);
        $verification = new TotpVerificationInputFilter();
        $verification->setData(['code' => $this->value('code')]);
        if (!$verification->isValid()) {
            foreach ($verification->getMessages()['code'] ?? [] as $key => $message) {
                $this->addMessage('code', $key, $message);
            }
        }
        $this->validateField('label', [new StringTrim()], [
            new NotEmpty(),
            new StringLength(['max' => 100]),
        ]);
    }
}
