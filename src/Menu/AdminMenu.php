<?php

declare(strict_types=1);

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Traits\KnpMenuHelperInterface;
use Survos\TablerBundle\Traits\KnpMenuHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The admin navbar.
 *
 * Separate from TabMenu, which is the phone tab bar in the Framework7 shell. These
 * screens are the Tabler skin — a desk UI — and the two menus deliberately do not
 * share entries.
 *
 * The marketplace links are only added when a connection of that driver is
 * configured, so an install with no Etsy credentials does not grow a link that
 * 404s.
 */
final class AdminMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    /** @param array<string, array{driver: string}> $connections */
    public function __construct(
        #[Autowire('%survos_marketplace.connections%')]
        private readonly array $connections = [],
    ) {
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_PRIMARY)]
    public function navbar(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        $this->add($menu, route: 'admin_item_index', label: 'Items', icon: 'tabler:list');

        foreach ($this->connections as $name => $config) {
            if ('etsy' !== ($config['driver'] ?? null)) {
                continue;
            }

            $this->add(
                $menu,
                route: 'etsy_shop',
                rp: ['connection' => $name],
                label: 'Etsy: ' . $name,
                icon: 'tabler:brand-etsy',
            );
        }
    }
}
