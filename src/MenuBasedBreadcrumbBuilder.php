<?php
/**
 * @file
 * MenuBasedBreadcrumbBuilder.php
 */
namespace Drupal\menu_breadcrumb;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * Class MenuBasedBreadcrumbBuilder
 * @package Drupal\menu_breadcrumb
 */
class MenuBasedBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuTree;
  /**
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  public function __construct(MenuLinkTreeInterface $menuTree, ConfigFactoryInterface $configFactory) {
    $this->menuTree = $menuTree;
    $this->configFactory = $configFactory;
    $this->config = $this->configFactory->getEditable('menu_breadcrumb.settings');
  }

  /**
   * Whether this breadcrumb builder should be used to build the breadcrumb.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return bool
   *   TRUE if this builder should be used or FALSE to let other builders
   *   decide.
   */
  public function applies(RouteMatchInterface $route_match) {
    return
      $this->config->get('determine_menu') &&
      (
        !$this->config->get('disable_admin_page') ||
        $this->config->get('disable_admin_page') && strpos($route_match->getRouteObject()->getPath(), '/admin') !== 0
      );
  }

  /**
   * Builds the breadcrumb.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return \Drupal\Core\Breadcrumb\Breadcrumb
   *   A breadcrumb.
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['url.path']);

    $menus = $this->config->get('menu_breadcrumb_menus');
    uasort($menus, function ($a, $b) {
      return SortArray::sortByWeightElement($a, $b);
    });

    $links = [];

    foreach ($menus as $menu_name => $params) {
      if (empty($params['enabled'])) {
        continue;
      }

      $parameters = $this->menuTree->getCurrentRouteMenuTreeParameters($menu_name);
      if (count($parameters->activeTrail) <= 1) {
        // This menu doesn't contain link to the current page.
        continue;
      }

      $tree = $this->menuTree->load($menu_name, $parameters);
      $manipulators = [
        ['callable' => 'menu.default_tree_manipulators:checkAccess'],
        ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ];
      $tree = $this->menuTree->transform($tree, $manipulators);
      $links = $this->getActiveTrail($tree);

      if (count($links)) {
        break;
      }
    }

    if (!count($links) && $this->config->get('hide_on_single_item')) {
      return $breadcrumb;
    }

    if (!$this->config->get('remove_home')) {
      $label = $this->config->get('home_as_site_name') ?
        $this->configFactory->get('system.site')->get('name') :
        t('Home');
      $home = Link::createFromRoute($label, '<front>');
      array_unshift($links, $home);
    }

    /** @var Link $current */
    $current = array_pop($links);

    if ($this->config->get('append_page_title')) {
      if (!$this->config->get('append_page_url')) {
        $current->setUrl(new Url('<none>'));
      }

      array_push($links, $current);
    }

    return $breadcrumb->setLinks($links);
  }

  /**
   * Generates links from menu tree
   * @param MenuLinkTreeElement[] $tree
   * @return Link[]
   */
  protected function getActiveTrail(array $tree) {
    $trail = [];
    /** @var MenuLinkTreeElement $element */
    foreach ($tree as $element) {
      if ($element->inActiveTrail) {
        $text = $element->link->getTitle();
        $url_object = $element->link->getUrlObject();
        if ($url_object->getRouteName() != "<front>") {
          $trail[] = Link::fromTextAndUrl($text, $url_object);
        }
        if ($element->hasChildren) {
          $trail = array_merge($trail, $this->getActiveTrail($element->subtree));
        }
      }
    }

    return $trail;
  }

}
