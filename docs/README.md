# MustUse Apps Publisher — Documentation

Internal docs for the Publisher plugin (`mustuse-apps-pub`). The top-level
[README](../README.md) is the friendly entry point — these pages are for
contributors and integrators who need to know how the system actually
works.

## Contents

- [**Architecture**](architecture.md) — how the publisher fits in the
  WordPress → GitHub → Bifrost → device chain. Custom post types, the
  manifest pipeline, async work, where data lives.
- [**Authoring**](authoring.md) — App and Screen editor surfaces, slug
  rules, the home flag, deep-link path templates, the screen tree, and
  the validation surface that gates Ship It.
- [**Blocks**](blocks.md) — block library categories, the atomic block
  pattern (`block.json` + `index.php` + `native.json` + `mobile.blade.php`),
  capability vocabulary, allowlists.
- [**Build and Ship**](build-and-ship.md) — what happens between clicking
  Ship It and Bifrost producing a binary. Blueprint, projection,
  manifest signing, env-var contract.
- [**REST API**](rest-api.md) — every endpoint the plugin exposes, who
  consumes it, and what the auth / cache / signature contracts look like.
- [**Extensions**](extensions.md) — filter surface for third-party
  plugins. Capability advertisement, deep-link resolvers, manifest
  shaping, content / context contributions.
- [**Development**](development.md) — local setup, testing, static
  analysis, the wp-env integration harness, releases, contributing
  conventions.

## Conventions

- Code paths are linked relative to the repo root using `src/...` syntax
  so they work both on GitHub and in IDE markdown previewers.
- Every doc starts with a one-paragraph "what this is" so the page is
  useful without needing to read the index.
- When a topic crosses two pages, the canonical home is whichever the
  reader is most likely to land on; the other page links across.
