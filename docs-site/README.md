# Articulate Documentation Site

The public documentation site for [`articulate-orm/core`](https://github.com/articulate-orm/core), built with [Astro Starlight](https://starlight.astro.build).

## Structure

- `src/content/docs/index.mdx` — landing page (hero + feature cards).
- `src/content/docs/concepts/` — core concepts (context-bounded entities, unit of work, hydration, caching).
- `src/content/docs/guides/` — task-oriented guides (getting started through known limitations), adapted from `articulate-demo/documentation/`.
- `src/content/docs/architecture/` — module map and Deptrac boundaries/conventions, adapted from the repo's `CLAUDE.md`.
- `src/content/docs/reference/` — ADRs and the changelog.
- `astro.config.mjs` — Starlight sidebar, site metadata, and `site`/`base` for GitHub Pages (`https://articulate-orm.github.io/core`).
- `src/styles/custom.css` — light/airy accent palette overrides.

## Development

```bash
npm install
npm run dev       # local dev server
npm run build     # static build to dist/
npm run preview   # preview the production build
```

## Deployment

Pushes to `main` that touch `docs-site/**` are built and deployed to GitHub Pages by `.github/workflows/docs-site.yml` at the repo root.
