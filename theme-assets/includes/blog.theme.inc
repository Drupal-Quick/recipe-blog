<?php

/**
 * @file
 * Blog recipe — theme preprocess callbacks and Schema.org JSON-LD.
 *
 * Injected into the custom theme by dq:scaffold when the blog recipe is
 * applied. Uses STARTERKIT_add_preprocessor() to register closures rather
 * than defining Drupal hook functions directly, which would conflict if
 * another recipe also registers for the same hook.
 *
 * dq:scaffold replaces STARTERKIT with the real theme machine name when
 * copying this file, so the calls below resolve to e.g.
 * my_theme_add_preprocessor() and _my_theme_article_jsonld().
 *
 * Structured data is built here by hand from the node's own fields — no
 * Metatag/Schema.org module is required, keeping the footprint light and the
 * output fully static-export friendly (Tome).
 */

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

STARTERKIT_add_preprocessor('node', function (array &$variables): void {
  /** @var \Drupal\node\NodeInterface $node */
  $node = $variables['node'];

  // The generic hook_preprocess() dispatches under the base hook 'node' for
  // every bundle, so scope this callback to Article nodes ourselves.
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

  // Emit Schema.org JSON-LD only on the standalone page (full view mode), never
  // on teasers or listing rows — duplicate BlogPosting blocks on one page are
  // invalid structured data. Printed in the template via {{ structured_data }}.
  $variables['structured_data'] = NULL;
  if (($variables['view_mode'] ?? '') === 'full') {
    $variables['structured_data'] = _STARTERKIT_article_jsonld($node, $keywords);
  }
});

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
function _STARTERKIT_article_jsonld($node, array $keywords): array {
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
