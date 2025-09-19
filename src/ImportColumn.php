<?php

namespace Konnco\FilamentImport;

use Filament\Support\Components\Component;
use Konnco\FilamentImport\Concerns\HasColumnMatching;
use Konnco\FilamentImport\Concerns\HasFieldLabel;
use Konnco\FilamentImport\Concerns\HasFieldMutation;
use Konnco\FilamentImport\Concerns\HasFieldValidation;

class ImportColumn extends Component
{
    use HasColumnMatching;
    use HasFieldLabel;
    use HasFieldMutation;
    use HasFieldValidation;

    protected string $name;

    private ?int $index = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(string $name): static
    {
        $static = app(static::class, ['name' => $name]);
        $static->configure();

        return $static;
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
