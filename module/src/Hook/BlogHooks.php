<?php

declare(strict_types=1);

namespace Drupal\dq_blog\Hook;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Blog recipe — theme hooks and Schema.org JSON-LD.
 *
 * Native object-oriented hooks (Drupal 11.3+). This module is a separate
 * extension, so its #[Hook] preprocess methods stack with the generated theme's
 * own preprocess and with other recipe modules — no shared dispatcher needed.
 *
 * Structured data is built here by hand from the node's own fields — no
 * Metatag/Schema.org module is required, keeping the footprint light and the
 * output fully static-export friendly (Tome). Markup lives in the theme; this
 * module only prepares variables.
 */
final class BlogHooks {

  /**
   * Implements hook_preprocess_HOOK() for node templates.
   *
   * Scoped to Article nodes; preprocess_node fires for every node bundle.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $variables['node'];
    if ($node->bundle() !== 'article') {
      return;
    }

    // Make Keywords available as a flat list of term label strings so
    // node--article.html.twig can render them without navigating entity refs.
    // The same list feeds the JSON-LD `keywords` property below.
    $keywords = [];
    if ($node->hasField('field_keywords') && !$node->get('field_keywords')->isEmpty()) {
      foreach ($node->get('field_keywords') as $item) {
        if ($item->entity) {
          $keywords[] = $item->entity->label();
        }
      }
    }
    $variables['keywords'] = $keywords;

    // Emit Schema.org JSON-LD only on the standalone page (full view mode),
    // never on teasers or listing rows — duplicate BlogPosting blocks on one
    // page are invalid structured data. Printed via {{ structured_data }}.
    $variables['structured_data'] = NULL;
    if (($variables['view_mode'] ?? '') === 'full') {
      $variables['structured_data'] = $this->articleJsonld($node, $keywords);
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for views templates.
   *
   * Scoped to the article-index view; preprocess_views_view fires for every
   * view.
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    $view = $variables['view'];
    if ($view->id() !== 'writing') {
      return;
    }

    // Flatten the result rows to the few values views-view--writing.html.twig
    // needs, so the template stays clean and platform-agnostic.
    $dateFormatter = \Drupal::service('date.formatter');
    $articles = [];
    foreach ($view->result as $row) {
      $node = $row->_entity ?? NULL;
      if (!$node) {
        continue;
      }
      $created = (int) $node->getCreatedTime();
      $articles[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'date_display' => $dateFormatter->format($created, 'custom', 'm-d-Y'),
        'datetime' => $dateFormatter->format($created, 'custom', 'Y-m-d'),
      ];
    }
    $variables['articles'] = $articles;
    $variables['structured_data'] = $this->articleListJsonld($view->result, $dateFormatter);
  }

  /**
   * Builds a Schema.org BlogPosting JSON-LD render element for an Article node.
   *
   * Assembled entirely from the node's fields — author, dates, lead image
   * (Media reference or plain image field), body summary and keywords — so no
   * SEO/metatag module is needed. Returns a `<script type="application/ld+json">`
   * render element; print it with {{ structured_data }} in the article template.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The article node being rendered.
   * @param string[] $keywords
   *   Flat list of keyword labels already resolved by the preprocessor.
   *
   * @return array
   *   A renderable html_tag element.
   */
  private function articleJsonld($node, array $keywords): array {
    $dateFormatter = \Drupal::service('date.formatter');
    $fileUrlGenerator = \Drupal::service('file_url_generator');

    // ISO 8601 dates in the site timezone (e.g. 2026-06-21T14:05:00-04:00).
    $data = [
      '@context' => 'https://schema.org',
      '@type' => 'BlogPosting',
      'headline' => $node->label(),
      'datePublished' => $dateFormatter->format($node->getCreatedTime(), 'custom', 'c'),
      'dateModified' => $dateFormatter->format($node->getChangedTime(), 'custom', 'c'),
      'mainEntityOfPage' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];

    // Author (Person).
    if ($owner = $node->getOwner()) {
      $data['author'] = [
        '@type' => 'Person',
        'name' => $owner->getDisplayName(),
      ];
    }

    // Publisher (Organization) from the site name + theme logo, if available.
    $siteName = \Drupal::config('system.site')->get('name');
    if ($siteName) {
      $publisher = ['@type' => 'Organization', 'name' => $siteName];
      $logo = theme_get_setting('logo.url');
      if (is_string($logo) && str_starts_with($logo, '/')) {
        $publisher['logo'] = [
          '@type' => 'ImageObject',
          'url' => Url::fromUserInput($logo, ['absolute' => TRUE])->toString(),
        ];
      }
      $data['publisher'] = $publisher;
    }

    // Lead image (absolute URL) — prefer the Media reference, fall back to the
    // plain image field. Google wants an absolute URL here.
    $imageUrl = NULL;
    if ($node->hasField('field_media') && !$node->get('field_media')->isEmpty()) {
      $media = $node->get('field_media')->entity;
      if ($media && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
        if ($file = $media->get('field_media_image')->entity) {
          $imageUrl = $fileUrlGenerator->generateAbsoluteString($file->getFileUri());
        }
      }
    }
    if (!$imageUrl && $node->hasField('field_image') && !$node->get('field_image')->isEmpty()) {
      if ($file = $node->get('field_image')->entity) {
        $imageUrl = $fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }
    if ($imageUrl) {
      $data['image'] = [$imageUrl];
    }

    // Description from the body summary (or a trimmed, tag-stripped body). Drop
    // <pre> code blocks first so example markup never leaks into the summary.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->first();
      $source = preg_replace('#<pre\b[^>]*>.*?</pre>#is', ' ', $body->summary ?: $body->value);
      $plain = trim(strip_tags($source));
      if ($plain !== '') {
        $data['description'] = Unicode::truncate($plain, 300, TRUE, TRUE);
      }
    }

    // Keywords as an array (a valid Schema.org form, clearer than a CSV string).
    if ($keywords) {
      $data['keywords'] = array_values($keywords);
    }

    // JSON_HEX_TAG escapes < and > to < / > so the payload can never
    // break out of the <script> element; slashes stay literal for clean URLs.
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    return [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#attributes' => ['type' => 'application/ld+json'],
      '#value' => Markup::create($json),
    ];
  }

  /**
   * Builds a Schema.org ItemList JSON-LD element for the article index.
   *
   * Each entry is a ListItem whose item is a BlogPosting (headline, absolute URL,
   * ISO datePublished), mirroring the per-article markup. Hand-built — no module.
   *
   * @param \Drupal\views\ResultRow[] $results
   *   The view's result rows.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   *
   * @return array|null
   *   A renderable html_tag element, or NULL when there are no rows.
   */
  private function articleListJsonld(array $results, $dateFormatter): ?array {
    $items = [];
    $position = 1;
    foreach ($results as $row) {
      $node = $row->_entity ?? NULL;
      if (!$node) {
        continue;
      }
      $items[] = [
        '@type' => 'ListItem',
        'position' => $position++,
        'item' => [
          '@type' => 'BlogPosting',
          'headline' => $node->label(),
          'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
          'datePublished' => $dateFormatter->format($node->getCreatedTime(), 'custom', 'c'),
        ],
      ];
    }
    if (!$items) {
      return NULL;
    }

    $data = [
      '@context' => 'https://schema.org',
      '@type' => 'ItemList',
      'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
      'numberOfItems' => count($items),
      'itemListElement' => $items,
    ];
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    return [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#attributes' => ['type' => 'application/ld+json'],
      '#value' => Markup::create($json),
    ];
  }

}
