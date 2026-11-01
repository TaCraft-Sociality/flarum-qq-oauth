<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth\Api\Controllers;

use Flarum\Api\Controller\UploadImageController;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TacraftSociality\QqOauth\Settings;
use TacraftSociality\QqOauth\Validators\IconValidator;

class UploadIconController extends UploadImageController
{
    private const MAX_DIMENSION = 256;

    protected string $filePathSettingKey = Settings::ICON_PATH;

    protected string $filenamePrefix = 'qq-oauth-icon';

    protected ?string $validator = IconValidator::class;

    private string $resolvedExtension = 'webp';

    protected function makeImage(UploadedFileInterface $file): EncodedImageInterface
    {
        $image = $this->imageManager->read($file->getStream()->getMetadata('uri'));

        if ($image->width() > self::MAX_DIMENSION || $image->height() > self::MAX_DIMENSION) {
            $image->scale(width: self::MAX_DIMENSION, height: self::MAX_DIMENSION);
        }

        if ($image->isAnimated()) {
            $this->resolvedExtension = 'gif';

            return $image->toGif();
        }

        $this->resolvedExtension = 'webp';

        return $image->toWebp();
    }

    protected function fileExtension(ServerRequestInterface $request, UploadedFileInterface $file): string
    {
        return $this->resolvedExtension;
    }
}
