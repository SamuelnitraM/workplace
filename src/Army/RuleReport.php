<?php

namespace App\Army;

/** Result of ArmyListRules::check(): blocking errors and non-blocking warnings, as messages for the member. */
final class RuleReport
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return array_values(array_unique($this->errors));
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
