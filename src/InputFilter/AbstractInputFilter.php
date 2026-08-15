<?php

declare(strict_types=1);

namespace LexNova\InputFilter;

use Laminas\Filter\FilterChain;
use Laminas\Filter\FilterInterface;
use Laminas\Filter\FilterPluginManager;
use Laminas\InputFilter\Input;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Validator\ValidatorChain;
use Laminas\Validator\ValidatorInterface;

abstract class AbstractInputFilter
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, array<string, string>> */
    private array $messages = [];

    private ?FilterPluginManager $filterPluginManager = null;

    /** @param array<string, mixed> $data */
    final public function setData(array $data): void
    {
        $this->data = $data;
        $this->values = [];
        $this->messages = [];
    }

    final public function isValid(): bool
    {
        $this->values = [];
        $this->messages = [];
        $this->validate();

        return $this->messages === [];
    }

    /** @return array<string, string> */
    final public function getValues(): array
    {
        return $this->values;
    }

    /** @return array<string, array<string, string>> */
    final public function getMessages(): array
    {
        return $this->messages;
    }

    /** @return list<string> */
    final public function getErrorMessages(): array
    {
        $messages = [];
        foreach ($this->messages as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }

    abstract protected function validate(): void;

    final protected function value(string $name): string
    {
        return $this->values[$name] ?? '';
    }

    final protected function addMessage(string $name, string $key, string $message): void
    {
        $this->messages[$name][$key] = $message;
    }

    /**
     * @param list<FilterInterface<mixed>> $filters
     * @param list<ValidatorInterface>     $validators
     */
    final protected function validateField(
        string $name,
        array $filters,
        array $validators,
        bool $allowEmpty = false,
    ): void {
        $rawValue = $this->data[$name] ?? '';
        if (!is_string($rawValue)) {
            $this->values[$name] = '';
            $this->messages[$name] = ['invalidType' => 'Value must be a string.'];

            return;
        }

        $this->filterPluginManager ??= new FilterPluginManager(new ServiceManager());
        $filterChain = new FilterChain($this->filterPluginManager, ['filters' => $filters]);
        $validatorChain = new ValidatorChain();
        foreach ($validators as $validator) {
            $validatorChain->attach($validator);
        }
        $input = new Input($filterChain, $validatorChain, $name, [
            'required' => !$allowEmpty,
            'allow_empty' => $allowEmpty,
        ]);
        $result = $input->validate($rawValue, $this->data);
        $filtered = $result->value();
        if (!is_string($filtered)) {
            $this->values[$name] = '';
            $this->messages[$name] = ['invalidType' => 'Value must be a string.'];

            return;
        }
        $this->values[$name] = $filtered;
        if (!$result->valid()) {
            /** @var array<string, string> $messages */
            $messages = $result->getMessages()->toArray();
            $this->messages[$name] = $messages;
        }
    }
}
