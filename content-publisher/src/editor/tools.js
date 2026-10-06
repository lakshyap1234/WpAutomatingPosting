// Pure helpers for the editor (tested with node --test): toolbar edits and the
// word-level comparison with the original text. Ported from the prototype's
// web/index.html and src/textdiff.js.

import { diffWords } from 'diff';

/**
 * A toolbar edit, as a replacement of value[from, to) by text, then a selection
 * (relative to `from`). The component applies it through the browser's
 * insertText so Undo keeps working.
 *
 * @typedef {{from:number, to:number, text:string, selFrom:number, selTo:number}} Edit
 */

const PREFIX = /^(#{1,6} |[-*+] |\d{1,9}[.)] )/;

/** Heading, list or plain paragraph for every line the selection touches. */
export function linePrefix(value, start, end, kind) {
  const from = value.lastIndexOf('\n', start - 1) + 1;
  let to = value.indexOf('\n', Math.max(end - (end > start && value[end - 1] === '\n' ? 1 : 0), from));
  if (to === -1) to = value.length;
  const lines = value.slice(from, to).split('\n');
  const want = { h2: '## ', h3: '### ', ul: '- ', ol: null, p: '' }[kind];
  const already = lines.every((l) => !l.trim() || (kind === 'ol' ? /^\d{1,9}[.)] /.test(l) : want && l.startsWith(want)));
  let n = 0;
  const text = lines
    .map((l) => {
      if (!l.trim()) return l;
      const bare = l.replace(PREFIX, '');
      if (already || kind === 'p') return bare;
      return (kind === 'ol' ? `${++n}. ` : want) + bare;
    })
    .join('\n');
  return { from, to, text, selFrom: 0, selTo: text.length };
}

/** **bold** / *italic* around the selection, or around a placeholder. */
export function wrapInline(value, start, end, mark, placeholder) {
  const sel = value.slice(start, end) || placeholder;
  return { from: start, to: end, text: mark + sel + mark, selFrom: mark.length, selTo: mark.length + sel.length };
}

export function link(value, start, end) {
  const sel = value.slice(start, end) || 'link text';
  const text = `[${sel}](https://)`;
  return { from: start, to: end, text, selFrom: sel.length + 3, selTo: sel.length + 11 };
}

export function lineBreak(value, start, end) {
  return { from: start, to: end, text: '\\\n', selFrom: 2, selTo: 2 };
}

/** A block on its own lines (blank line before and after), part of it selected. */
export function block(value, start, end, text, selFrom, selTo) {
  const before = value.slice(0, end);
  const lead = !before ? '' : before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n';
  return { from: end, to: end, text: `${lead}${text}\n\n`, selFrom: lead.length + selFrom, selTo: lead.length + selTo };
}

export const table = (v, s, e) => block(v, s, e, '| Column 1 | Column 2 |\n| --- | --- |\n| Cell | Cell |', 2, 10);
export const webImage = (v, s, e) => block(v, s, e, '![Describe the image](https:// "Optional caption")', 22, 30);

/** An uploaded image by file name (angle brackets keep spaces and brackets safe). */
export function fileImage(v, s, e, filename) {
  const dest = /[\s()<>]/.test(filename) ? `<${filename.replace(/[<>]/g, '')}>` : filename;
  const text = `![Describe the image](${dest})`;
  return block(v, s, e, text, 2, 20);
}

/** Insert a removed line back where the cursor is, as its own paragraph. */
export function insertLine(v, s, e, markdown) {
  return block(v, s, e, markdown, 0, markdown.length);
}

/** Character range of a 1-based line. */
export function lineRange(value, n) {
  const lines = value.split('\n');
  const start = lines.slice(0, n - 1).reduce((a, l) => a + l.length + 1, 0);
  return [start, start + (lines[n - 1] || '').length];
}

// ---- comparison with the original text ----

const CONTEXT_WORDS = 6;
const MAX_HUNKS = 50;
const lastWords = (s, n) => s.split(/(\s+)/).slice(-n * 2).join('').trimStart();
const firstWords = (s, n) => s.split(/(\s+)/).slice(0, n * 2).join('').trimEnd();

/**
 * Word changes between the original text and the post as it is now.
 * Formatting isn't counted: both sides are plain text.
 *
 * @returns {{count:number, hunks:{before:string, removed:string, added:string, after:string}[], truncated:boolean}}
 */
export function textChanges(original, current) {
  const parts = diffWords(original || '', current || '');
  const hunks = [];
  let i = 0;
  while (i < parts.length) {
    if (!parts[i].added && !parts[i].removed) {
      i++;
      continue;
    }
    const before = i > 0 ? lastWords(parts[i - 1].value, CONTEXT_WORDS) : '';
    let removed = '';
    let added = '';
    while (i < parts.length && (parts[i].added || parts[i].removed)) {
      if (parts[i].removed) removed += parts[i].value;
      else added += parts[i].value;
      i++;
    }
    const after = i < parts.length ? firstWords(parts[i].value, CONTEXT_WORDS) : '';
    if (removed.trim() || added.trim()) hunks.push({ before, removed: removed.trim(), added: added.trim(), after });
  }
  return { count: hunks.length, hunks: hunks.slice(0, MAX_HUNKS), truncated: hunks.length > MAX_HUNKS };
}

export const squash = (s) => String(s || '').replace(/\s+/g, ' ').trim();

// ------------------------------------------------------------ sending options

const pad = (n) => String(n).padStart(2, '0');

/** ISO time -> value for <input type="datetime-local">, in this computer's time zone. */
export function toLocalInput(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** <input type="datetime-local"> value -> ISO time (UTC), or '' when empty. */
export function fromLocalInput(v) {
  if (!v) return '';
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? '' : d.toISOString();
}

/** Whether two times (ISO strings, or empty) are the same moment. */
export function sameTime(a, b) {
  if (!a || !b) return !a && !b;
  return new Date(a).getTime() === new Date(b).getTime();
}

/** What approving does, from the server's target: draft, redraft (replaces a sent draft), publish, schedule or update (a live post). */
export function approveKind(target) {
  if (!target) return 'draft';
  if (target.status === 'draft') return /^Update/.test(target.label) ? 'redraft' : 'draft';
  if (/^Schedule/.test(target.label)) return 'schedule';
  if (/^Update/.test(target.label)) return 'update';
  return 'publish';
}
