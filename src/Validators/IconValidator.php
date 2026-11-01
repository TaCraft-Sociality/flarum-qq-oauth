<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth\Validators;

use Flarum\Foundation\AbstractImageValidator;

class IconValidator extends AbstractImageValidator
{
    public function getMaxSize(): int
    {
        return 1024;
    }

    public function getMaxResolution(): int
    {
        return 4_000_000;
    }

    protected function getAllowedTypes(): array
    {
        return ['jpeg', 'jpg', 'png', 'bmp', 'gif', 'webp'];
    }
}
