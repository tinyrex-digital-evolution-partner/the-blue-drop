<?php

declare(strict_types=1);

namespace Drupal\mercury\Hook;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\ChainBreadcrumbBuilderInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
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
    private readonly MenuLinkManagerInterface $menuLinkManager,
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
  private const HERO_NODE_TYPES = ['blog_post', 'tutorial', 'content_page'];

  /**
   * View routes that render their own hero and manage title/breadcrumb themselves.
   */
  private const HERO_VIEW_ROUTES = ['view.blog.page_2', 'view.tutorial.page_1'];

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

    // Suppress title and breadcrumb from the header for node types, view pages,
    // and user profiles that render their own hero (they handle these elements internally).
    $node = $this->routeMatch->getParameter('node');
    $routeName = $this->routeMatch->getRouteName();
    if (
      ($node instanceof NodeInterface && in_array($node->bundle(), self::HERO_NODE_TYPES, TRUE))
      || in_array($routeName, self::HERO_VIEW_ROUTES, TRUE)
      || $routeName === 'entity.user.canonical'
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

    // Pass rendered breadcrumb and extra variables to node templates that
    // manage their own hero/layout.
    if (in_array($node->bundle(), self::HERO_NODE_TYPES, TRUE) && $variables['view_mode'] === 'full') {
      if ($node->bundle() === 'blog_post') {
        // Blog post: Home > Blog > [Category] > [Title]
        $links = [
          Link::createFromRoute($this->t('Home'), '<front>'),
          new Link($this->t('Blog'), Url::fromRoute('view.blog.page_2')),
        ];
        if ($node->hasField('field_blog_post_topics')) {
          $topics = $node->get('field_blog_post_topics')->referencedEntities();
          if (!empty($topics)) {
            /** @var \Drupal\taxonomy\TermInterface $term */
            $term = reset($topics);
            $slug = mb_strtolower(str_replace(' ', '-', $term->label()));
            $links[] = new Link(
              $term->label(),
              Url::fromRoute('view.blog.page_2', ['arg_0' => $slug]),
            );
          }
        }
        $links[] = Link::createFromRoute($node->label(), '<none>');
      }
      elseif ($node->bundle() === 'tutorial') {
        // Tutorial: Home > Tutorial > [Category] > [Title]
        $links = [
          Link::createFromRoute($this->t('Home'), '<front>'),
          new Link($this->t('Tutorial'), Url::fromRoute('view.tutorial.page_1')),
        ];
        if ($node->hasField('field_tutorial_topics')) {
          $topics = $node->get('field_tutorial_topics')->referencedEntities();
          if (!empty($topics)) {
            /** @var \Drupal\taxonomy\TermInterface $term */
            $term = reset($topics);
            $slug = mb_strtolower(str_replace(' ', '-', $term->label()));
            $links[] = new Link(
              $term->label(),
              Url::fromRoute('view.tutorial.page_1', ['arg_0' => $slug]),
            );
            // Pass the topic hex color to the template so the hero can use it
            // as dynamic background via --hero-custom-color CSS variable.
            if ($term->hasField('field_tutorial_topics_color')) {
              $hex = $term->get('field_tutorial_topics_color')->value;
              if (!empty($hex)) {
                $variables['tutorial_topic_color'] = $hex;
              }
            }
          }
        }
        $links[] = Link::createFromRoute($node->label(), '<none>');
      }
      elseif ($node->bundle() === 'content_page') {
        // Content page: Home > [Title]
        $links = [
          Link::createFromRoute($this->t('Home'), '<front>'),
          Link::createFromRoute($node->label(), '<none>'),
        ];

        // Find the menu link for this node to determine which menu to render.
        $node_route = 'entity.node.canonical';
        $node_route_params = ['node' => $node->id()];
        
        // Search for menu links that point to this node.
        $menu_links = $this->menuLinkManager->loadLinksByRoute($node_route, $node_route_params);
        
        $parent_menu_name = NULL;
        
        if (!empty($menu_links)) {
          // Get the first menu link (if node appears in multiple menus, use the first one).
          $menu_link = reset($menu_links);
          $parent_menu_name = $menu_link->getMenuName();
        }
        
        // If we found a parent menu, load and render it.
        if ($parent_menu_name) {
          $parameters = new MenuTreeParameters();
          $parameters->setMaxDepth(2)->onlyEnabledLinks();
          $tree = $this->menuLinkTree->load($parent_menu_name, $parameters);
          $manipulators = [
            ['callable' => 'menu.default_tree_manipulators:checkAccess'],
            ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
          ];
          $tree = $this->menuLinkTree->transform($tree, $manipulators);
          
          // Get current node URL to compare with menu items.
          $currentUrl = $node->toUrl()->toString();
          
          // Mark menu items as active if they link to the current node.
          foreach ($tree as $element) {
            $link = $element->link;
            if ($link->getUrlObject()->isRouted()) {
              try {
                $itemUrl = $link->getUrlObject()->toString();
                if ($itemUrl === $currentUrl) {
                  $element->inActiveTrail = TRUE;
                }
              }
              catch (\Exception $e) {
                // Skip if URL cannot be generated.
              }
            }
          }
          
          $variables['parent_menu'] = $this->menuLinkTree->build($tree);
          $variables['has_parent_menu'] = TRUE;
        }
        else {
          $variables['has_parent_menu'] = FALSE;
        }
      }
      else {
        $links = [];
      }

      $breadcrumb = new Breadcrumb();
      $breadcrumb->setLinks($links);
      $breadcrumb->addCacheContexts(['url.path']);
      $breadcrumb->addCacheableDependency($node);
      $variables['page_breadcrumb'] = $breadcrumb->toRenderable();
    }
    
    // Pass author's profile title for all blog post view modes (full, teaser, etc.).
    if ($node->bundle() === 'blog_post') {
      $author = $node->getOwner();
      if ($author && $author->hasField('field_user_title')) {
        $profile_title = $author->get('field_user_title')->value;
        if (!empty($profile_title)) {
          $variables['author_profile_title'] = $profile_title;
        }
      }
    }
  }

  /**
   * Implements template_preprocess_views_view().
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    $view = $variables['view'];

    if ($view->id() === 'blog' && $view->current_display === 'page_2') {
      // When a taxonomy argument is present (e.g. /blog/digital-transformation),
      // Easy Breadcrumb cannot resolve the category title dynamically because
      // the view's display title is always the static "Blog" string. Build the
      // breadcrumb manually so the category label is the actual term name.
      $args = $view->args;
      if (!empty($args[0])) {
        $slug = $args[0];
        $term_name_search = str_replace(['-', '_'], ' ', $slug);
        $terms = $this->entityTypeManager->getStorage('taxonomy_term')
          ->loadByProperties(['name' => $term_name_search, 'vid' => 'blog_topics']);
        $term_label = !empty($terms)
          ? reset($terms)->label()
          : ucwords($term_name_search);

        $breadcrumb = new Breadcrumb();
        $breadcrumb->setLinks([
          Link::createFromRoute($this->t('Home'), '<front>'),
          new Link($this->t('Blog'), Url::fromRoute('view.blog.page_2')),
          Link::createFromRoute($term_label, '<none>'),
        ]);
        $breadcrumb->addCacheContexts(['url.path']);
        $variables['page_breadcrumb'] = $breadcrumb->toRenderable();
      }
      else {
        // No argument: standard breadcrumb for the main blog listing.
        $variables['page_breadcrumb'] = $this->breadcrumb
          ->build($this->routeMatch)
          ->toRenderable();
      }

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

    if ($view->id() === 'tutorial' && $view->current_display === 'page_1') {
      // Build breadcrumb — same pattern as blog.page_2: the view display title
      // is always the static "Tutorial" string so we resolve it manually.
      $args = $view->args;
      if (!empty($args[0])) {
        $slug = $args[0];
        $term_name_search = str_replace(['-', '_'], ' ', $slug);
        $terms = $this->entityTypeManager->getStorage('taxonomy_term')
          ->loadByProperties(['name' => $term_name_search, 'vid' => 'tutorial_topics']);
        $term_label = !empty($terms)
          ? reset($terms)->label()
          : ucwords($term_name_search);

        $breadcrumb = new Breadcrumb();
        $breadcrumb->setLinks([
          Link::createFromRoute($this->t('Home'), '<front>'),
          new Link($this->t('Tutorial'), Url::fromRoute('view.tutorial.page_1')),
          Link::createFromRoute($term_label, '<none>'),
        ]);
        $breadcrumb->addCacheContexts(['url.path']);
        $variables['page_breadcrumb'] = $breadcrumb->toRenderable();
      }
      else {
        $variables['page_breadcrumb'] = $this->breadcrumb
          ->build($this->routeMatch)
          ->toRenderable();
      }

      // Pass rendered tutorial-menu tree.
      $parameters = new MenuTreeParameters();
      $parameters->setMaxDepth(2)->onlyEnabledLinks();
      $tree = $this->menuLinkTree->load('tutorial-menu', $parameters);
      $manipulators = [
        ['callable' => 'menu.default_tree_manipulators:checkAccess'],
        ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ];
      $tree = $this->menuLinkTree->transform($tree, $manipulators);
      $variables['tutorial_menu'] = $this->menuLinkTree->build($tree);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for user profiles.
   */
  #[Hook('preprocess_user')]
  public function preprocessUser(array &$variables): void {
    /** @var \Drupal\user\UserInterface $user */
    $user = $variables['user'];
    $view_mode = $variables['elements']['#view_mode'] ?? 'default';

    // Pass variables for template conditionals.
    $variables['view_mode'] = $view_mode;
    $variables['username'] = $user->getDisplayName();
    $variables['user_id'] = $user->id();
  }

}
