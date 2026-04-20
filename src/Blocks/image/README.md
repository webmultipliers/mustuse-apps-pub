# Image Block

**Pattern demonstrated:** Image rendering with optional caption.

The image block renders an editorial image with an optional caption below it. It shows how to:

- Use `image` with `src`, `aspect_ratio`, and `fit` properties
- Access nested attribute fields (`{{ attributes.image.url }}`)
- Use `visible_when` to conditionally render the caption text
- Compose `image` + `text` in a `stack` for figure-like layouts

Start here if your block involves media display with accompanying metadata.
