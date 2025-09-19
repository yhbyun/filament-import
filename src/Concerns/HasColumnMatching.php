<?php

namespace Konnco\FilamentImport\Concerns;

trait HasColumnMatching
{
    protected string|array $alternativeColumnNames = '';

    public function alternativeColumnNames(string|array $alternativeColumnNames): static
    {
        $this->alternativeColumnNames = $alternativeColumnNames;

        return $this;
    }

    public function getAlternativeColumnNames(): string|array
    {
        return $this->alternativeColumnNames;
    }
}
