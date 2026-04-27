<?php

declare(strict_types=1);

namespace Survos\AiPipelineBundle\Menu;

use Survos\AiPipelineBundle\Entity\AiTaskRun;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class AiPipelineMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string { return 'AI Pipeline'; }
    protected function getResourceClasses(): array { return [AiTaskRun::class]; }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $this->buildAdminMenu($event);
    }
}
