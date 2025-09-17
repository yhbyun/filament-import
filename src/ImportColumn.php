<?php

namespace Konnco\FilamentImport;

use Konnco\FilamentImport\Concerns\HasColumnMatching;
use Konnco\FilamentImport\Concerns\HasFieldLabel;
use Konnco\FilamentImport\Concerns\HasFieldMutation;
use Konnco\FilamentImport\Concerns\HasFieldValidation;

class ImportColumn
{
    use HasColumnMatching;
    use HasFieldLabel;
    use HasFieldMutation;
    use HasFieldValidation;

    private ?int $index = null;

    public function __construct(private string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function index(int $index): void
    {
        $this->index = $index;
    }

    public function getIndex(): ?int
    {
        return $this->index;
    }
}
