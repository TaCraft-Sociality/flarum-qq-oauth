<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth\Listeners;

use Flarum\User\AvatarUploader;
use Flarum\User\Event\RegisteringFromProvider;
use Intervention\Image\ImageManager;
use Psr\Log\LoggerInterface;
use TacraftSociality\QqOauth\QQ\QQAvatar;
use Throwable;

class SyncQQAvatar
{
    public function __construct(
        private readonly AvatarUploader $avatars,
        private readonly ImageManager $images,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(RegisteringFromProvider $event): void
    {
        if ($event->provider !== 'qq') {
            return;
        }

        $urls = $event->payload['avatar_urls'] ?? null;

        if (! is_array($urls) || $urls === []) {
            return;
        }

        try {
            $contents = QQAvatar::download($urls);

            if ($contents === null) {
                return;
            }

            $this->avatars->upload($event->user, $this->images->read($contents));
        } catch (Throwable $e) {
            $event->user->changeAvatarPath(null);

            $this->logger->warning('[flarum-qq-oauth] avatar sync failed: '.$e->getMessage());
        }
    }
}
