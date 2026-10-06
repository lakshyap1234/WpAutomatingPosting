// Builds the review editor: src/editor/ -> build/editor.js, build/editor.css and
// build/editor.asset.php (the WordPress script dependencies and a content
// version), like @wordpress/scripts. Run with `npm ci && npm run build`.
//
// React, api-fetch and the components come from WordPress itself (the wp.*
// globals), so they are not bundled; editor.asset.php lists them as script
// dependencies. esbuild is pinned, so the same source gives the same bytes.
import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url)); // the plugin folder, whatever the current directory
const out = join(root, 'build');

const globals = { '@wordpress/element': ['element', 'wp-element'], '@wordpress/api-fetch': ['apiFetch', 'wp-api-fetch'], '@wordpress/components': ['components', 'wp-components'] };
const deps = new Set();
const wpGlobals = {
  name: 'wp-globals',
  setup(b) {
    b.onResolve({ filter: /^@wordpress\// }, (a) => {
      if (!globals[a.path]) throw new Error(`Unmapped WordPress package ${a.path}`);
      return { path: a.path, namespace: 'wp-global' };
    });
    b.onLoad({ filter: /.*/, namespace: 'wp-global' }, (a) => {
      const [name, handle] = globals[a.path];
      deps.add(handle);
      const expr = `window.wp.${name}`;
      return { contents: `const m = ${expr}; module.exports = m && m.default && Object.keys(m).length === 1 ? m.default : m;`, loader: 'js' };
    });
  },
};

mkdirSync(out, { recursive: true });
await build({
  entryPoints: { editor: join(root, 'src/editor/index.jsx') },
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2019'],
  outdir: out,
  jsxFactory: 'window.wp.element.createElement',
  jsxFragment: 'window.wp.element.Fragment',
  plugins: [wpGlobals],
  legalComments: 'none',
  logLevel: 'warning',
});
deps.add('wp-element');
const hash = createHash('sha256').update(readFileSync(join(out, 'editor.js'))).update(readFileSync(join(out, 'editor.css'))).digest('hex').slice(0, 20);
writeFileSync(join(out, 'editor.asset.php'), `<?php return array( 'dependencies' => array( ${[...deps].sort().map((d) => `'${d}'`).join(', ')} ), 'version' => '${hash}' );\n`);
console.log('built build/editor.js', [...deps].sort().join(', '), hash);
