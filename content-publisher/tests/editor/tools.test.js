import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as T from '../../src/editor/tools.js';

const apply = (value, e) => value.slice(0, e.from) + e.text + value.slice(e.to);

test('line prefixes: heading, lists, paragraph, and toggling off', () => {
  const v = 'Intro\nfirst\nsecond\n\nend';
  const e = T.linePrefix(v, 7, 15, 'ul'); // selection inside "first" .. "second"
  assert.equal(apply(v, e), 'Intro\n- first\n- second\n\nend');
  const v2 = apply(v, e);
  assert.equal(apply(v2, T.linePrefix(v2, 8, 16, 'ul')), v, 'applying again removes the marker');
  assert.equal(apply(v, T.linePrefix(v, 7, 15, 'ol')), 'Intro\n1. first\n2. second\n\nend');
  assert.equal(apply('## Head', T.linePrefix('## Head', 3, 3, 'p')), 'Head');
  assert.equal(apply('- item', T.linePrefix('- item', 0, 0, 'h2')), '## item');
});

test('inline marks, links, blocks keep a blank line around them', () => {
  const b = T.wrapInline('say hi now', 4, 6, '**', 'bold text');
  assert.equal(apply('say hi now', b), 'say **hi** now');
  assert.deepEqual([b.selFrom, b.selTo], [2, 4]);
  assert.equal(apply('x', T.wrapInline('x', 1, 1, '*', 'italic text')), 'x*italic text*');
  assert.equal(apply('see this', T.link('see this', 4, 8)), 'see [this](https://)');
  assert.equal(apply('Para', T.table('Para', 4, 4)), 'Para\n\n| Column 1 | Column 2 |\n| --- | --- |\n| Cell | Cell |\n\n');
  assert.equal(apply('A\n\n', T.webImage('A\n\n', 3, 3)), 'A\n\n![Describe the image](https:// "Optional caption")\n\n');
  assert.equal(apply('', T.fileImage('', 0, 0, 'my photo (1).jpg')), '![Describe the image](<my photo (1).jpg>)\n\n');
  assert.equal(apply('A', T.insertLine('A', 1, 1, 'By Sara')), 'A\n\nBy Sara\n\n');
});

test('line ranges for jumping to a problem', () => {
  assert.deepEqual(T.lineRange('a\nbb\nccc', 2), [2, 4]);
  assert.deepEqual(T.lineRange('a\nbb\nccc', 3), [5, 8]);
});

test('text changes: context, removed and added words', () => {
  const r = T.textChanges('The quick brown fox jumps over the lazy dog', 'The quick red fox jumps over the dog');
  assert.equal(r.count, 2);
  const h = r.hunks[0];
  assert.deepEqual([h.before.trim(), h.removed, h.added, h.after.trim()], ['The quick', 'brown', 'red', 'fox jumps over the']);
  assert.equal(r.hunks[1].removed, 'lazy');
  assert.equal(T.textChanges('same text', 'same text').count, 0);
});

test('publish date round-trips through the date field', () => {
  const iso = '2031-05-01T09:30:00.000Z';
  const local = T.toLocalInput(iso);
  assert.match(local, /^2031-0[45]-\d\dT\d\d:30$/);
  assert.equal(T.fromLocalInput(local), iso);
  assert.equal(T.toLocalInput(''), '');
  assert.equal(T.toLocalInput('nonsense'), '');
  assert.equal(T.fromLocalInput(''), '');
});

test('same moment in different notations', () => {
  assert.ok(T.sameTime('2031-05-01T09:30:00+00:00', '2031-05-01T09:30:00.000Z'));
  assert.ok(T.sameTime('', ''));
  assert.ok(!T.sameTime('', '2031-05-01T09:30:00Z'));
  assert.ok(!T.sameTime('2031-05-01T09:30:00Z', '2031-05-01T09:31:00Z'));
});

test('approve button follows what approving does', () => {
  assert.equal(T.approveKind(null), 'draft');
  assert.equal(T.approveKind({ status: 'draft', label: 'Send to X as a draft' }), 'draft');
  assert.equal(T.approveKind({ status: 'draft', label: 'Update the draft on X' }), 'redraft');
  assert.equal(T.approveKind({ status: 'publish', label: 'Update the scheduled post on X (it keeps its date)' }), 'update');
  assert.equal(T.approveKind({ status: 'publish', label: 'Publish on X now' }), 'publish');
  assert.equal(T.approveKind({ status: 'publish', label: 'Schedule on X for 1 May' }), 'schedule');
  assert.equal(T.approveKind({ status: 'publish', label: 'Update the published post on X' }), 'update');
});
