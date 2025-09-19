<?php

namespace Konnco\FilamentImport\Concerns;

use Closure;

trait HasFieldValidation
{
    protected array|string|Closure $rules = [];

    protected $customMessages = [];

    public function rules(array|string|Closure $rules, $customMessages = []): static
    {
        $this->rules = $rules;
        $this->customMessages = $customMessages;

        return $this;
    }

    public function getValidationRules()
    {
        return $this->evaluate($this->rules);
    }

    public function getCustomValidationMessages()
    {
        return $this->customMessages;
    }
}
