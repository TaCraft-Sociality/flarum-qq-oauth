<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth\Api\Controllers;

use Flarum\Api\Controller\AbstractDeleteController;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Http\Message\ServerRequestInterface;
use TacraftSociality\QqOauth\Settings;

class DeleteIconController extends AbstractDeleteController
{
    protected Filesystem $uploadDir;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        Factory $filesystemFactory
    ) {
        $this->uploadDir = $filesystemFactory->disk(Settings::ASSETS_DISK);
    }

    protected function delete(ServerRequestInterface $request): void
    {
        RequestUtil::getActor($request)->assertAdmin();

        $path = $this->settings->get(Settings::ICON_PATH);

        $this->settings->set(Settings::ICON_PATH, '');

        if (is_string($path) && $path !== '' && $this->uploadDir->exists($path)) {
            $this->uploadDir->delete($path);
        }
    }
}
