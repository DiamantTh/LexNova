<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\Callback as CallbackFilter;
use Laminas\Filter\StringTrim;
use Laminas\Validator\Callback;
use Laminas\Validator\Digits;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Validates and filters the document create/update form.
 *
 * Handles all syntactic validation (field presence, allowed values, BCP 47
 * language tag). Business-rule validation (e.g. entity existence) stays in
 * the calling handler.
 */
final class DocumentInputFilter extends AbstractInputFilter
{
    protected function validate(): void
    {
        $this->validateField('entity_id', [new StringTrim()], [new NotEmpty(), new Digits()]);
        $this->validateField('type', [new StringTrim()], [
            new NotEmpty(),
            new InArray([
                'haystack' => ['imprint', 'privacy'],
                'strict' => InArray::COMPARE_STRICT,
                'messages' => [InArray::NOT_IN_ARRAY => 'Type must be "imprint" or "privacy".'],
            ]),
        ]);
        $this->validateField('language', [new StringTrim()], [
            new NotEmpty(),
            new Callback([
                'callback' => static function (string $tag): bool {
                    if (!preg_match('/^[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*$/', $tag)) {
                        return false;
                    }
                    $parsed = \Locale::parseLocale($tag);

                    return isset($parsed['language']);
                },
                'messages' => [
                    Callback::INVALID_VALUE => 'Language must be a valid BCP 47 tag (e.g. de, en-US, fr-CH).',
                ],
            ]),
        ]);
        $this->validateField('content', [
            new CallbackFilter(
                static fn (string $v): string => str_replace(["\r\n", "\r"], "\n", $v),
            ),
            new StringTrim(),
        ], [new NotEmpty(), new StringLength(['max' => 1000000])]);
        $this->validateField(
            'version',
            [new StringTrim()],
            [new NotEmpty(), new StringLength(['max' => 50])],
        );
    }
}
