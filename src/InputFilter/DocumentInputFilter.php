<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Callback;
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
final class DocumentInputFilter extends InputFilter
{
    public function __construct()
    {
        $this->add($this->buildEntityIdInput());
        $this->add($this->buildTypeInput());
        $this->add($this->buildLanguageInput());
        $this->add($this->buildContentInput());
        $this->add($this->buildVersionInput());
    }

    private function buildEntityIdInput(): Input
    {
        $input = new Input('entity_id');
        $input->getFilterChain()
            ->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new NotEmpty());

        return $input;
    }

    private function buildTypeInput(): Input
    {
        $input = new Input('type');
        $input->getFilterChain()
            ->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new NotEmpty())
            ->attach(new InArray([
                'haystack' => ['imprint', 'privacy'],
                'strict' => InArray::COMPARE_STRICT,
                'messages' => [InArray::NOT_IN_ARRAY => 'Type must be "imprint" or "privacy".'],
            ]));

        return $input;
    }

    private function buildLanguageInput(): Input
    {
        $input = new Input('language');
        $input->getFilterChain()
            ->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new NotEmpty())
            ->attach(new Callback([
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
            ]));

        return $input;
    }

    private function buildContentInput(): Input
    {
        $input = new Input('content');
        $input->getFilterChain()
            ->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new NotEmpty());

        return $input;
    }

    private function buildVersionInput(): Input
    {
        $input = new Input('version');
        $input->getFilterChain()
            ->attach(new StringTrim());
        $input->getValidatorChain()
            ->attach(new NotEmpty())
            ->attach(new StringLength(['max' => 50]));

        return $input;
    }
}
