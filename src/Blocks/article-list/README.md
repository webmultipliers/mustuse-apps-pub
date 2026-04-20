# Article List Block

**Pattern demonstrated:** Data fetching from a pub endpoint with iteration.

The article-list block fetches posts from the pub's content endpoint and renders each as a list item. It shows how to:

- Use `data` to fetch from a pub REST endpoint at render time
- Bind fetched data into the rendering scope with `bind_to`
- Use `foreach`/`as`/`render` to iterate over a collection
- Access item fields (`{{ post.title }}`, `{{ post.excerpt }}`, `{{ post.featured_image }}`)
- Combine `visible_when` with attribute checks for conditional display of thumbnails and excerpts

This is the canonical data-fetching example. Start here if your block needs to display dynamic content from a REST endpoint.
