<?php

declare(strict_types=1);

namespace Drupal\mercury\Hook;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Breadcrumb\ChainBreadcrumbBuilderInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Contains hook implementations for Mercury.
 */
final class ThemeHooks {

  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * The Drupal root.
   */
  private static ?string $appRoot = NULL;

  public function __construct(
    private readonly ThemeSettingsProvider $themeSettings,
    private readonly RequestStack $requestStack,
    private readonly ThemeExtensionList $themeList,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly TitleResolverInterface $titleResolver,
    private readonly ChainBreadcrumbBuilderInterface $breadcrumb,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly MenuLinkTreeInterface $menuLinkTree,
    #[Autowire(param: 'app.root')] string $appRoot,
  ) {
    self::$appRoot ??= $appRoot;
  }

  /**
   * Implements hook_library_info_alter().
   */
  #[Hook('library_info_alter')]
  public function alterLibraryInfo(array &$libraries, string $extension): void {
    $override = static function (string $name, string $replacement) use (&$libraries): void {
      $old_parents = ['global', 'css', 'theme', $name];
      $new_parents = [...array_slice($old_parents, 0, -1), $replacement];
      $css_settings = NestedArray::getValue($libraries, $old_parents);
      NestedArray::setValue($libraries, $new_parents, $css_settings);
      NestedArray::unsetValue($libraries, $old_parents);
    };
    if ($extension === 'mercury') {
      if (file_exists(self::$appRoot . '/theme.css')) {
        $override('src/theme.css', '/theme.css');
      }
      if (file_exists(self::$appRoot . '/fonts.css')) {
        $override('src/fonts.css', '/fonts.css');
      }
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   */
  #[Hook('form_system_theme_settings_alter')]
  public function themeSettingsFormAlter(array &$form): void {
    $form['scheme'] = [
      '#type' => 'radios',
      '#title' => t('Color scheme'),
      '#default_value' => $this->themeSettings->getSetting('scheme'),
      '#options' => [
        'light' => t('Light'),
        'dark' => t('Dark'),
      ],
    ];
    $message = $this->t("See <code>@path</code> to learn how to customize Mercury's fonts, colors, and components.", [
      '@path' => $this->themeList->getPath('mercury') . '/CUSTOMIZING.md',
    ]);
    $this->messenger()->addMessage($message, 'info');
  }

  /**
   * Implements template_preprocess_image_widget().
   */
  #[Hook('preprocess_image_widget')]
  public function preprocessImageWidget(array &$variables): void {
    $data = &$variables['data'];

    // This prevents image widget templates from rendering preview container
    // HTML to users that do not have permission to access these previews.
    // @todo revisit in https://drupal.org/node/953034
    // @todo revisit in https://drupal.org/node/3114318
    if (isset($data['preview']['#access']) && $data['preview']['#access'] === FALSE) {
      unset($data['preview']);
    }
  }

  /**
   * Implements template_preprocess_html().
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    $variables['scheme'] = $this->themeSettings->getSetting('scheme');
    // Get the theme base path for font preloading.
    $variables['mercury_path'] = $this->requestStack->getCurrentRequest()->getBasePath() . '/' . $this->themeList->getPath('mercury');
  }

  /**
   * Node types that render their own hero and manage title/breadcrumb themselves.
   */
  private const HERO_NODE_TYPES = ['blog_post'];

  /**
   * View routes that render their own hero and manage title/breadcrumb themselves.
   */
  private const HERO_VIEW_ROUTES = ['view.blog.page_2'];

  /**
   * Implements template_preprocess_page().
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    // @see \Drupal\Core\Block\Plugin\Block\PageTitleBlock::build()
    $variables['title'] = [
      '#type' => 'page_title',
      '#title' => $variables['page']['#title'] ?? $this->titleResolver->getTitle(
        $this->requestStack->getCurrentRequest(),
        $this->routeMatch->getRouteObject(),
      ),
    ];

    // @see \Drupal\system\Plugin\Block\SystemBreadcrumbBlock::build()
    $variables['breadcrumb'] = $this->breadcrumb->build($this->routeMatch)
      ->toRenderable();

    // Suppress title and breadcrumb from the header for node types and view
    // pages that render their own hero (they handle these elements internally).
    $node = $this->routeMatch->getParameter('node');
    $routeName = $this->routeMatch->getRouteName();
    if (
      ($node instanceof NodeInterface && in_array($node->bundle(), self::HERO_NODE_TYPES, TRUE))
      || in_array($routeName, self::HERO_VIEW_ROUTES, TRUE)
    ) {
      $variables['title'] = NULL;
      $variables['breadcrumb'] = NULL;
    }

    $route_name = $this->routeMatch->getRouteName();
    if ($route_name === 'entity.canvas_page.canonical' || str_starts_with($this->routeMatch->getRouteObject()?->getPath() ?? '', '/canvas/')) {
      $variables['rendered_by_canvas'] = TRUE;
    }
    elseif ($route_name === 'entity.node.canonical' && $this->moduleHandler->moduleExists('canvas')) {
      $node = $this->routeMatch->getParameter('node');
      assert($node instanceof NodeInterface);

      $variables['rendered_by_canvas'] = (bool) $this->entityTypeManager->getStorage('content_template')
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->condition('content_entity_type_id', 'node')
        ->condition('content_entity_type_bundle', $node->getType())
        ->condition('content_entity_type_view_mode', 'full')
        ->condition('status', TRUE)
        ->execute();
    }
    else {
      $variables['rendered_by_canvas'] = FALSE;
    }
  }

  /**
   * Implements template_preprocess_node().
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $variables['node'];

    // Pass rendered breadcrumb to node templates that manage their own layout.
    if (in_array($node->bundle(), self::HERO_NODE_TYPES, TRUE) && $variables['view_mode'] === 'full') {
      $variables['page_breadcrumb'] = $this->breadcrumb
        ->build($this->routeMatch)
        ->toRenderable();
    }
  }

  /**
   * Implements template_preprocess_views_view().
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    $view = $variables['view'];

    if ($view->id() === 'blog' && $view->current_display === 'page_2') {
      // Pass breadcrumb.
      $variables['page_breadcrumb'] = $this->breadcrumb
        ->build($this->routeMatch)
        ->toRenderable();

      // Pass rendered blog-menu tree.
      $parameters = new MenuTreeParameters();
      $parameters->setMaxDepth(2)->onlyEnabledLinks();
      $tree = $this->menuLinkTree->load('blog-menu', $parameters);
      $manipulators = [
        ['callable' => 'menu.default_tree_manipulators:checkAccess'],
        ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ];
      $tree = $this->menuLinkTree->transform($tree, $manipulators);
      $variables['blog_menu'] = $this->menuLinkTree->build($tree);
    }
  }

}
