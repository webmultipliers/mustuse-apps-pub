# Article Card Block

**Pattern demonstrated:** Single-post fetching with attribute binding.

The article-card block fetches a single post selected via the post picker and renders it as a featured card. It shows how to:

- Use `data` with a specific `post_id` parameter for single-record fetching
- Bind a single record's fields (`{{ article.title }}`, `{{ article.excerpt }}`)
- Use `container` with border radius and background for card-style presentation
- Combine `visible_when` with toggle attributes to show/hide card sections

This is the single-record variant of the data-fetching pattern. Start here if your block displays one selected item rather than a collection.
