# recipe-blog

> **Work in progress** — this is a proof-of-concept recipe used to develop and test the [Quick](https://github.com/Drupal-Quick/drupal-quick) scaffolding workflow. The API and config are not yet stable.

A `drupal-recipe` package that adds a minimal blog layer to a standard Drupal install.

## What it does

- Adds a **Keywords** taxonomy vocabulary and an entity reference field (`field_keywords`) to the Article content type
- Creates a **Writing** view (`/writing`) that lists articles by date, with both a page display and a block display
- Sets the site front page to `/writing`
- Ships **theme assets** that `dq:scaffold` injects into the generated theme:
  - `templates/content/node--article.html.twig` — article card markup with Schema.org `BlogPosting` JSON-LD
  - `templates/views/views-view--writing.html.twig` — the article list with `ItemList` JSON-LD and right-aligned dates
  - `includes/blog.theme.inc` — preprocessors for the above (wired via the `dq_starterkit` preprocess dispatcher)

## Dependencies

Depends on the Article content type being present. Apply `core/recipes/standard` before this recipe, or any other recipe that installs Article.

## Usage

This recipe is consumed automatically by [Quick](https://github.com/Drupal-Quick/drupal-quick). Add `"blog"` to the `recipes:` list in `config.dq.yml` and run `composer exec dq-install` followed by `drush dq:scaffold`. See the [Quick workflow](https://github.com/Drupal-Quick/drupal-quick/blob/main/docs/workflow.md) for the full steps.

To apply it manually:

```bash
composer require drupal-quick/recipe-blog
drush recipe recipes/recipe-blog
```
