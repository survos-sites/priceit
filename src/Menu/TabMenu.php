<?php

declare(strict_types=1);

namespace App\Menu;

use Survos\FwBundle\Event\KnpMenuEvent;
use Survos\FwBundle\Menu\KnpMenuHelperInterface;
use Survos\FwBundle\Menu\KnpMenuHelperTrait;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The bottom tab bar.
 *
 * MOBILE_TAB_MENU_LINKED, not MOBILE_TAB_MENU: the latter renders href="#tab-{name}" anchors
 * into a client-side tab set held in one document, and every screen here is its own Symfony
 * route rendered on the server -- which is also why f7_controller disables F7's router.
 *
 * This replaces a hardcoded tab array in base.html.twig. The array worked, but its active
 * state was hand-rolled, down to an active_for list so an item detail page kept "Items" lit.
 * KnpMenu's matcher already answers that from the route.
 */
final class TabMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    #[AsEventListener(event: KnpMenuEvent::MOBILE_TAB_MENU_LINKED)]
    public function tabMenu(KnpMenuEvent $event): void
    {
        $menu = $event->getMenu();

        // First, because it is what most volunteers came for: what is it, what do we charge.
        $this->add($menu, route: 'app_lookup', label: 'Price it', icon: 'tabler:search');
        $this->add($menu, route: 'item_capture', label: 'Capture', icon: 'tabler:camera');

        $items = $this->add($menu, route: 'app_item_index', label: 'Items', icon: 'tabler:list', returnItem: true);

        // Dropping into an item is still being in Items, and KnpMenu's RouteVoter matches the
        // exact route only -- so without this the tabbar goes blank on a detail page, which
        // reads as having left the app. The voter reads this extra as a list
        // (Knp\Menu\Matcher\Voter\RouteVoter), and add() supports 'routes' internally but does
        // not expose it as an argument.
        $items->setExtra('routes', ['app_item_index', 'app_item_show']);
    }
}
