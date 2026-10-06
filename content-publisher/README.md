# Content Publisher (agency side)

WordPress plugin. User-facing documentation is in `readme.txt`.

## The review editor

The editor screen (Content Publisher > Posts > a post) is a React app.

| Path | What it is |
| --- | --- |
| `src/editor/` | Editor source: `index.jsx` (mounts it), `Editor.jsx` (the screen), `tools.js` (pure helpers: toolbar edits, the word-level comparison with the original, sending options), `editor.css` |
| `tests/editor/` | Tests for `tools.js` (`node --test`) |
| `build.mjs` | Build config (esbuild) |
| `package.json`, `package-lock.json` | esbuild and jsdiff, pinned |
| `build/` | **Generated. Don't edit by hand.** `editor.js`, `editor.css`, `editor.asset.php` |

Build (Node 18 or later):

```sh
npm ci
npm test        # optional: the helper tests
npm run build   # writes build/editor.js, build/editor.css, build/editor.asset.php
```

`build/` is committed, so the plugin runs on a server without Node, and release zips leave out the source and Node files. After changing anything in `src/editor/`, run the build and commit `build/` together with the source. esbuild is pinned, so the same source always gives the same bytes. `editor.asset.php` lists the WordPress scripts the editor uses (`wp-element`, `wp-api-fetch`, `wp-components`). React and the components come from WordPress itself, not the bundle; only jsdiff is bundled. Its `version` is a hash of the built files, so browsers fetch the new build after a change.

PHP side: `src/Admin/EditorPage.php` loads the built files and passes `window.cpubEditor` (job id, links, upload limit). `src/Rest/JobsController.php` is the REST API the editor calls (`cpub/v1/jobs/...`).
