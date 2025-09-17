<?php

namespace Konnco\FilamentImport\Concerns;

use Closure;

trait HasValidation
{
    protected Closure $actionAfterValidation;

    public function actionAfterValidation(Closure $fn): static
    {
        $this->actionAfterValidation = $fn;

        return $this;
    }

    public function doActionAfterValidation(array $result)
    {
        $closure = $this->actionAfterValidation;

        return $closure($result);
    }
}
