# Design — Public FAQ

## Data

`faq_categories`, `faq_articles`, `faq_article_versions`.

## Search

Database full-text/simple indexed search is sufficient for MVP. Search is not allowed to expose drafts.

## Routes

- `/faq`
- `/faq/kategori/{categorySlug}`
- `/faq/{articleSlug}`
