import { useState, useEffect, useRef, useCallback, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Modal, TextareaControl, Spinner, FormTokenField } from '@wordpress/components';
import * as T from './tools';

const cfg = window.cpubEditor || {};
const path = (p = '') => `/cpub/v1/jobs/${cfg.jobId}${p}`;
const GUIDE_KEY = 'cpub:guide';
const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;
const when = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

const { toLocalInput, fromLocalInput, sameTime, approveKind } = T;
const APPROVE_TEXT = { draft: 'Approve and send as a draft', redraft: 'Approve and update the draft', publish: 'Approve and publish', schedule: 'Approve and schedule', update: 'Approve and update the live post' };

function readGuide() {
  try {
    return localStorage.getItem(GUIDE_KEY) !== 'hidden';
  } catch (e) {
    return true;
  }
}

export default function Editor() {
  const [job, setJob] = useState(null);
  const [markdown, setMarkdown] = useState('');
  const [saved, setSaved] = useState('');
  const [preview, setPreview] = useState(null);
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState(null); // {status, text, problems?, conflict?}
  const [guide, setGuide] = useState(readGuide);
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [tags, setTags] = useState([]);
  const [publishAt, setPublishAt] = useState('');
  const [suggestions, setSuggestions] = useState([]);
  const ed = useRef(null);
  const seq = useRef(0);
  const leaving = useRef(false); // navigating away on purpose: no "unsaved changes" prompt
  const optionsDirty = !!job && (JSON.stringify(tags) !== JSON.stringify(job.tags || []) || !sameTime(fromLocalInput(publishAt), job.publish_at));
  const dirty = markdown !== saved || optionsDirty;
  const options = () => ({ tags, publish_at: fromLocalInput(publishAt) });
  const takeOptions = (j) => {
    setTags(j.tags || []);
    setPublishAt(toLocalInput(j.publish_at));
  };

  const load = useCallback(async () => {
    try {
      const j = await apiFetch({ path: path() });
      // A status changed while we watched (processed, sent…): the last "done" message is out of date.
      setJob((prev) => {
        if (prev && prev.status !== j.status) setMessage((m) => (m && m.status === 'success' ? null : m));
        return j;
      });
      setMarkdown(j.markdown);
      setSaved(j.markdown);
      takeOptions(j);
      return j;
    } catch (e) {
      setMessage({ status: 'error', text: e.message || 'Could not load the post.' });
      return null;
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  // While the post is queued or processing, check back every few seconds.
  useEffect(() => {
    if (!job || !['queued', 'processing', 'approved', 'sending'].includes(job.status)) return undefined;
    const t = setTimeout(load, 5000);
    return () => clearTimeout(t);
  }, [job, load]);

  // Live preview, a moment after typing stops.
  useEffect(() => {
    if (!job || !markdown.trim()) {
      setPreview(null);
      return undefined;
    }
    const n = ++seq.current;
    const t = setTimeout(async () => {
      try {
        const p = await apiFetch({ path: path('/preview'), method: 'POST', data: { markdown } });
        if (n === seq.current) setPreview(p);
      } catch (e) {
        if (n === seq.current) setMessage({ status: 'error', text: e.message });
      }
    }, 300);
    return () => clearTimeout(t);
  }, [markdown, job && job.id, job && job.images.map((i) => i.id).join(',')]); // eslint-disable-line

  // Warn before leaving with unsaved changes.
  useEffect(() => {
    const h = (e) => {
      if (dirty && !leaving.current) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  /**
   * Run an action that returns the job's new state.
   * mode 'replace': show the server's text (approve, reopen, rerun…).
   * mode 'save': the text was sent; anything typed since stays, still unsaved.
   * mode 'images': only the images changed; unsaved text stays. If someone else
   *   changed the text meanwhile, keep the old revision so the next save is
   *   refused as a conflict instead of overwriting their change.
   */
  const act = async (label, request, done, mode = 'replace') => {
    setBusy(label);
    setMessage(null);
    const lastSaved = saved;
    try {
      const j = await apiFetch(request);
      if (j.deleted) {
        leaving.current = true;
        window.location.href = cfg.postsUrl;
        return;
      }
      if (mode === 'images' && j.markdown !== lastSaved) {
        setJob({ ...j, revision: job.revision });
        setMessage({ status: 'warning', text: 'Someone else changed this post’s text meanwhile. Saving now will be refused; reload to see their version.' });
        return;
      }
      setJob(j);
      if (mode !== 'images') takeOptions(j);
      if (mode === 'replace') setMarkdown(j.markdown);
      if (mode !== 'images') setSaved(j.markdown);
      if (done) setMessage({ status: 'success', text: done });
    } catch (e) {
      const conflict = e.code === 'cpub_conflict';
      setMessage({ status: 'error', text: e.message || 'Something went wrong.', problems: e.data && e.data.problems, conflict });
    } finally {
      setBusy('');
    }
  };

  const save = () => act('Saving…', { path: path(), method: 'PUT', data: { markdown, revision: job.revision, ...options() } }, 'Saved.', 'save');
  const approve = async () => {
    // What approving does right now (the site's settings may have changed since this page loaded).
    let target = job.target;
    try {
      target = (await apiFetch({ path: path() })).target || target;
    } catch (e) {
      // use what we have
    }
    const kind = approveKind(target);
    const where = job.site ? job.site.url : 'the client site';
    // Anything that goes live, or replaces what is on the site, gets a second look.
    if (['publish', 'schedule', 'update'].includes(kind) && !window.confirm(`${target.label}?\n\nIt will be live on ${where} without the site's editors seeing it first.`)) return;
    if (kind === 'redraft' && !window.confirm(`${target.label}?\n\nThis replaces the draft on ${where}, including any changes made to it there.`)) return;
    act('Approving…', { path: path('/approve'), method: 'POST', data: { markdown, revision: job.revision, ...options() } }, `Approved. ${target ? target.label : 'Sending to the client site'}…`);
  };
  const unpublish = () => {
    if (!window.confirm(`Take this post off ${job.site ? job.site.name : 'the client site'}? It goes back to being a draft there.`)) return;
    act('Unpublishing…', { path: path('/unpublish'), method: 'POST' }, 'Unpublished: it is a draft on the client site again.');
  };

  // Tag suggestions: the client site's own tags, searched as you type.
  const siteId = job && job.site ? job.site.id : 0;
  const searchTags = useCallback(
    (() => {
      let t;
      return (text) => {
        clearTimeout(t);
        t = setTimeout(async () => {
          if (!siteId) return;
          try {
            const r = await apiFetch({ path: `/cpub/v1/sites/${siteId}/tags?search=${encodeURIComponent(text || '')}` });
            setSuggestions(r.tags || []);
          } catch (e) {
            setSuggestions([]);
          }
        }, 250);
      };
    })(),
    [siteId]
  );
  useEffect(() => {
    if (job && job.can.edit) searchTags('');
  }, [siteId, job && job.can.edit]); // eslint-disable-line
  const reopen = () => act('Reopening…', { path: path('/reopen'), method: 'POST', data: { revision: job.revision } }, 'Reopened for review.');
  const rerun = () => {
    if (!window.confirm('Send this post back to the AI? The current version and any edits are replaced by a fresh one.')) return;
    act('Sending to the AI…', { path: path('/rerun'), method: 'POST', data: { revision: job.revision } });
  };
  const retry = () => act('Retrying…', { path: path('/retry'), method: 'POST' });
  const sendAgain = () => act('Sending again…', { path: path('/send'), method: 'POST' });
  const checkNow = () => act('Checking the client site…', { path: path('/check'), method: 'POST' }, 'Checked.');
  const plain = () => act('Setting out as paragraphs…', { path: path('/plain'), method: 'POST' });
  const remove = () => {
    if (!window.confirm('Delete this post and its files? This can’t be undone.')) return;
    act('Deleting…', { path: path(), method: 'DELETE' });
  };
  const reject = () => {
    act('Rejecting…', { path: path('/reject'), method: 'POST', data: { reason, revision: job.revision } }, 'Rejected.');
    setRejecting(false);
    setReason('');
  };

  // Ctrl+S saves.
  useEffect(() => {
    const h = (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's' && job && job.can.edit) {
        e.preventDefault();
        if (dirty && !busy) save();
      }
    };
    document.addEventListener('keydown', h);
    return () => document.removeEventListener('keydown', h);
  });

  /** Apply a toolbar edit through the browser, so Undo works. */
  const apply = (fn, ...args) => {
    const el = ed.current;
    if (!el || !job.can.edit) return;
    const edit = fn(el.value, el.selectionStart, el.selectionEnd, ...args);
    el.focus();
    el.setSelectionRange(edit.from, edit.to);
    if (!document.execCommand('insertText', false, edit.text)) {
      el.setRangeText(edit.text, edit.from, edit.to, 'end');
      setMarkdown(el.value);
    }
    el.setSelectionRange(edit.from + edit.selFrom, edit.from + edit.selTo);
  };

  const goToLine = (n) => {
    const el = ed.current;
    if (!el) return;
    const [a, b] = T.lineRange(el.value, n);
    el.focus();
    el.setSelectionRange(a, b);
    const lh = parseFloat(getComputedStyle(el).lineHeight) || 22;
    el.scrollTop = Math.max(0, (n - 4) * lh);
  };

  const upload = async (files) => {
    for (const f of Array.from(files || [])) {
      if (f.size > cfg.maxImage) {
        setMessage({ status: 'error', text: `${f.name} is larger than ${Math.round(cfg.maxImage / 1048576)} MB.` });
        continue;
      }
      const body = new FormData();
      body.append('file', f, f.name);
      // eslint-disable-next-line no-await-in-loop
      await act(`Uploading ${f.name}…`, { path: path('/images'), method: 'POST', body }, '', 'images');
    }
  };
  const deleteImage = (img) => {
    if (!window.confirm(`Remove ${img.filename}?`)) return;
    act('Removing…', { path: path(`/images/${img.id}`), method: 'DELETE' }, '', 'images');
  };

  const changes = useMemo(() => {
    if (!job || !job.original || !preview) return null;
    return T.textChanges(T.squash(job.original.body_text), preview.plain_text);
  }, [job && job.original, preview]); // eslint-disable-line

  if (!job) {
    return message ? <Notice status="error" isDismissible={false}>{message.text}</Notice> : <p><Spinner /> Loading…</p>;
  }

  const editable = job.can.edit;
  const pending = ['queued', 'processing'].includes(job.status);
  const missing = preview ? preview.images.filter((i) => !i.file && !/^https?:\/\//i.test(i.src)) : [];
  const problems = preview ? preview.errors : [];
  const site = job.site;

  return (
    <div className={`cpub-editor status-${job.status}`}>
      <header className="cpub-head">
        <div className="cpub-titles">
          <a href={cfg.postsUrl} className="cpub-back">← Posts</a>
          <h1>{job.title || job.source_name}</h1>
          <p className="cpub-meta">
            <span className={`cpub-badge cpub-badge-${job.status}`}>{job.status_text}</span>
            {site && <span>for <strong>{site.name}</strong></span>}
            <span>{job.source_name}</span>
            {job.ai && <span>structured by {job.ai}</span>}
            {job.uploaded_by && <span>uploaded by {job.uploaded_by}, {when(job.created_at)}</span>}
          </p>
        </div>
        <div className="cpub-actions">
          {busy && <span className="cpub-busy"><Spinner /> {busy}</span>}
          {!busy && editable && dirty && <span className="cpub-dirty">Unsaved changes</span>}
          {editable && <Button variant="secondary" onClick={save} disabled={!dirty || !!busy} id="cpub-save">Save</Button>}
          {job.can.reject && <Button variant="secondary" isDestructive onClick={() => setRejecting(true)} disabled={!!busy} id="cpub-reject">Reject…</Button>}
          {job.can.approve && (
            <Button variant="primary" onClick={approve} disabled={!!busy || problems.length > 0 || !preview} id="cpub-approve" data-kind={approveKind(job.target)} title={problems.length ? 'Fix the problems under the editor first' : (job.target ? job.target.label : '')}>
              {APPROVE_TEXT[approveKind(job.target)]}
            </Button>
          )}
          {job.can.unpublish && <Button variant="secondary" isDestructive onClick={unpublish} disabled={!!busy} id="cpub-unpublish">Unpublish</Button>}
          {job.can.reopen && <Button variant="secondary" onClick={reopen} disabled={!!busy} id="cpub-reopen">Reopen for review</Button>}
        </div>
      </header>

      {message && (
        <Notice status={message.status} onRemove={() => setMessage(null)} className="cpub-message">
          {message.text}
          {message.problems && (
            <ul>{message.problems.map((p, i) => <li key={i}>{p.line ? `Line ${p.line}: ` : ''}{p.message}</li>)}</ul>
          )}
          {message.conflict && (
            <p>
              <Button variant="secondary" onClick={() => { leaving.current = true; window.location.reload(); }}>Reload (discards your changes)</Button>
            </p>
          )}
        </Notice>
      )}
      {(job.status === 'approved' || job.status === 'sending') && (
        <Notice status="info" isDismissible={false} className="cpub-sending">
          <Spinner /> Approved by {job.reviewed_by || 'a reviewer'}. {job.status === 'sending' ? 'Working on it' : 'Waiting its turn'}: {job.target ? job.target.label : 'sending to the client site'}… This page updates by itself.
          {job.sending_note && <> {job.sending_note}</>}
        </Notice>
      )}
      {job.status === 'sent' && job.sent && (
        <Notice status="success" isDismissible={false} className={`cpub-sent${job.remote_post_status === 'future' ? ' cpub-scheduled' : ''}`}>
          <p><strong>{job.remote_post_status === 'future' ? `Scheduled on ${site ? site.name : 'the client site'}` : `On ${site ? site.name : 'the client site'} as a draft`}</strong>{job.publish_at && job.remote_post_status === 'future' && <> for {when(job.publish_at)}</>} {job.sent.at && <>(sent {when(job.sent.at)})</>}. Category: {job.sent.category || 'the site’s default'}.{job.sent.images ? ` ${plural(job.sent.images, 'image')} in its Media Library.` : ''}</p>
          {job.sent.tags_missing && job.sent.tags_missing.length > 0 && <p>Tags not on the site, so not added: {job.sent.tags_missing.join(', ')}. The client can create them and add them to the post.</p>}
          {job.client_deleted && <p><strong>The draft has since been deleted on the client site.</strong></p>}
          <p>
            <a className="components-button is-primary" href={job.sent.edit_url} target="_blank" rel="noopener noreferrer" id="cpub-edit-remote">Open the draft on {site ? site.name : 'the client site'}</a>{' '}
            {job.sent.preview_url && <a className="components-button is-secondary" href={job.sent.preview_url} target="_blank" rel="noopener noreferrer">Preview it there</a>}{' '}
            <Button variant="tertiary" onClick={checkNow} disabled={!!busy} id="cpub-check">Check if it’s published</Button>
          </p>
          <p className="description">To change it, reopen it for review and approve it again: the post on the client site is updated, replacing any changes made there.</p>
        </Notice>
      )}
      {job.status === 'published' && (
        <Notice status="success" isDismissible={false} className="cpub-published">
          <p><strong>Live on {site ? site.name : 'the client site'}</strong>{job.published && job.published.at && <> since {when(job.published.at)}</>}.{' '}
          {job.published && job.published.link && <a href={job.published.link} target="_blank" rel="noopener noreferrer" id="cpub-view-live">View the post</a>}</p>
          {job.sent && job.sent.tags_missing && job.sent.tags_missing.length > 0 && <p>Tags not on the site, so not added: {job.sent.tags_missing.join(', ')}.</p>}
          <p className="description">
            {job.files_deleted ? `Our copies of the source file and images were deleted on ${when(job.files_deleted)}. ` : 'Our copies of the source file and images are kept for 30 days after it went live, then deleted. '}
            {job.can.reopen ? 'To correct it, reopen it for review and approve again: the live post is updated.' : 'Changes are now made on the client site.'}
          </p>
        </Notice>
      )}
      {job.status === 'send_failed' && (
        <Notice status="error" isDismissible={false} className="cpub-send-failed">
          <p><strong>Sending failed:</strong> {job.last_error}</p>
          <p>
            <Button variant="primary" onClick={sendAgain} disabled={!!busy} id="cpub-send-again">Send again</Button>{' '}
            <Button variant="secondary" onClick={reopen} disabled={!!busy}>Reopen for review</Button>
          </p>
        </Notice>
      )}
      {job.status === 'rejected' && (
        <Notice status="warning" isDismissible={false}>
          Rejected by {job.reviewed_by || 'a reviewer'}: {job.review_note}
        </Notice>
      )}
      {pending && (
        <Notice status="info" isDismissible={false}>
          <Spinner /> {job.status === 'queued' ? 'Waiting to be processed' : 'Being processed'}… This page updates by itself.
          {job.last_error && <> Last attempt: {job.last_error}</>}
        </Notice>
      )}
      {job.status === 'failed' && (
        <Notice status="error" isDismissible={false}>
          <p><strong>Processing failed:</strong> {job.last_error}</p>
          <p>
            <Button variant="primary" onClick={retry} disabled={!!busy} id="cpub-retry">Try again</Button>{' '}
            {job.can.plain && <Button variant="secondary" onClick={plain} disabled={!!busy} id="cpub-plain">Set out as plain paragraphs, without the AI</Button>}
          </p>
        </Notice>
      )}

      {!pending && job.status !== 'failed' && (
        <div className="cpub-grid">
          <aside className="cpub-side">
            {site && (
              <section className="cpub-panel" id="cpub-publishing">
                <h2>On {site.name}</h2>
                {job.target && <p className="cpub-target" data-status={job.target.status}><strong>{job.target.label}.</strong></p>}
                {job.target && job.target.note && <p className="description">{job.target.note}</p>}
                {site.author && <p className="description">Appears under {site.author}.</p>}
                {editable ? (
                  <>
                    <FormTokenField
                      label="Tags"
                      value={tags}
                      suggestions={suggestions}
                      onInputChange={searchTags}
                      onChange={(v) => setTags(v.map((t) => (typeof t === 'string' ? t : t.value)).filter(Boolean).slice(0, 20))}
                      maxSuggestions={20}
                      __experimentalExpandOnFocus
                      __next40pxDefaultSize
                      __nextHasNoMarginBottom
                    />
                    <p className="description" id="cpub-tags-note">
                      {site.can_create_tags ? 'Pick the site’s tags, or type a new one: new tags are added to the site.' : 'Only tags that already exist on the site are added; new ones are listed for the site to create.'}
                    </p>
                    {site.can_publish && site.settings && site.settings.send_as === 'publish' && (
                      <p>
                        <label htmlFor="cpub-publish-at"><strong>Publish date</strong> (optional)</label><br />
                        <input type="datetime-local" id="cpub-publish-at" value={publishAt} onChange={(e) => setPublishAt(e.target.value)} />{' '}
                        {publishAt && <Button variant="link" onClick={() => setPublishAt('')}>Clear</Button>}
                        <br /><span className="description">Empty: published when approved. A later date schedules it (your computer’s time zone).</span>
                      </p>
                    )}
                    {optionsDirty && <p className="cpub-dirty">Unsaved changes</p>}
                  </>
                ) : (
                  <>
                    {job.tags && job.tags.length > 0 && <p>Tags: {job.tags.join(', ')}</p>}
                    {job.publish_at && <p>Publish date: {when(job.publish_at)}</p>}
                  </>
                )}
              </section>
            )}
            {job.warnings.length > 0 && (
              <section className="cpub-panel cpub-warn" id="cpub-notes">
                <h2>{job.can.approve ? 'Check before approving' : 'Notes from processing'}</h2>
                <ul>{job.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul>
              </section>
            )}
            {job.removed.length > 0 && (
              <section className="cpub-panel" id="cpub-removed">
                <h2>Left out of the post</h2>
                <p className="description">Bylines, dates and tag lines. Insert one if it belongs in the post.</p>
                <ul className="cpub-list">
                  {job.removed.map((r, i) => (
                    <li key={i}>
                      <span><code>{r.text}</code> <em>{{ metadata: 'tags/category', byline: 'byline', date: 'date' }[r.kind] || r.kind}</em></span>
                      {editable && <Button variant="link" onClick={() => apply(T.insertLine, r.markdown)}>Insert</Button>}
                    </li>
                  ))}
                </ul>
              </section>
            )}
            <section className="cpub-panel" id="cpub-images">
              <h2>Images</h2>
              {missing.length > 0 && (
                <p className="cpub-missing">Missing: {missing.map((m) => m.src).join(', ')}. Upload files with these names.</p>
              )}
              {job.images.length === 0 && <p className="description">No image files. A post can also use images by web address.</p>}
              <ul className="cpub-images">
                {job.images.map((img) => (
                  <li key={img.id}>
                    <img src={img.url} alt="" loading="lazy" />
                    <div>
                      <strong>{img.filename}</strong>
                      <span className="description">{img.used ? 'in the post' : 'not used'}{img.origin === 'docx' ? ' · from the Word file' : ''}</span>
                      {editable && !img.used && <Button variant="link" onClick={() => apply(T.fileImage, img.filename)}>Insert</Button>}
                      {editable && <Button variant="link" isDestructive onClick={() => deleteImage(img)}>Remove</Button>}
                    </div>
                  </li>
                ))}
              </ul>
              {job.can.images && (
                <label className="components-button is-secondary cpub-upload">
                  Upload images
                  <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple onChange={(e) => { upload(e.target.files); e.target.value = ''; }} id="cpub-image-upload" />
                </label>
              )}
            </section>
            {site && (
              <section className="cpub-panel">
                <h2>Where it goes</h2>
                <p><strong>{site.name}</strong> ({site.url}), {job.target && job.target.status === 'publish' ? 'published' : 'as a draft'}; category: {site.settings && site.settings.default_category ? site.settings.default_category.name : 'the site’s default'}{site.settings && site.settings.use_post_category ? ', unless the post’s “Category:” line names one of the site’s' : ''}.</p>
              </section>
            )}
            <section className="cpub-panel" id="cpub-history">
              <h2>History</h2>
              <ol className="cpub-history">
                {job.history.map((h, i) => (
                  <li key={i}><time>{when(h.at)}</time> {h.user && <strong>{h.user}:</strong>} {h.message}</li>
                ))}
              </ol>
              <div className="cpub-links">
                {job.can.rerun && <Button variant="link" onClick={rerun} disabled={!!busy} id="cpub-rerun">Run the AI again</Button>}
                {job.can.delete && <Button variant="link" isDestructive onClick={remove} disabled={!!busy} id="cpub-delete">Delete post</Button>}
              </div>
            </section>
          </aside>

          <section className="cpub-pane">
            <div className="cpub-pane-head">
              <h2>Post (Markdown)</h2>
              <Button variant="link" onClick={() => { setGuide(!guide); try { localStorage.setItem(GUIDE_KEY, guide ? 'hidden' : 'shown'); } catch (e) {} }}>
                {guide ? 'Hide formatting guide' : 'Show formatting guide'}
              </Button>
            </div>
            {editable && (
              <div className="cpub-toolbar" role="toolbar" aria-label="Formatting">
                <Button onClick={() => apply(T.linePrefix, 'h2')} title="Section heading (## )">Heading</Button>
                <Button onClick={() => apply(T.linePrefix, 'h3')} title="Smaller heading (### )">Subheading</Button>
                <Button onClick={() => apply(T.linePrefix, 'p')} title="Plain paragraph">Paragraph</Button>
                <span className="sep" />
                <Button onClick={() => apply(T.linePrefix, 'ul')} title="Bullet list (- )">• List</Button>
                <Button onClick={() => apply(T.linePrefix, 'ol')} title="Numbered list (1. )">1. List</Button>
                <span className="sep" />
                <Button onClick={() => apply(T.wrapInline, '**', 'bold text')} title="Bold, Ctrl+B"><b>B</b></Button>
                <Button onClick={() => apply(T.wrapInline, '*', 'italic text')} title="Italic, Ctrl+I"><i>I</i></Button>
                <Button onClick={() => apply(T.link)} title="Link">Link</Button>
                <Button onClick={() => apply(T.lineBreak)} title="Line break inside a paragraph">Line break</Button>
                <span className="sep" />
                <Button onClick={() => apply(T.table)} title="Insert a table">Table</Button>
                <Button onClick={() => apply(T.webImage)} title="Image from a web address">Image</Button>
              </div>
            )}
            {guide && <Guide />}
            <textarea
              ref={ed}
              id="cpub-markdown"
              className="cpub-textarea"
              value={markdown}
              readOnly={!editable}
              spellCheck
              aria-label="Post in Markdown"
              onChange={(e) => setMarkdown(e.target.value)}
              onKeyDown={(e) => {
                if (!(e.ctrlKey || e.metaKey) || !editable) return;
                if (e.key === 'b') { e.preventDefault(); apply(T.wrapInline, '**', 'bold text'); }
                if (e.key === 'i') { e.preventDefault(); apply(T.wrapInline, '*', 'italic text'); }
              }}
            />
            <div className="cpub-below">
              {problems.length > 0 && (
                <div className="cpub-problems" id="cpub-problems">
                  <strong>Fix before approving:</strong>
                  <ul>
                    {problems.map((p, i) => (
                      <li key={i}>{p.line ? <Button variant="link" onClick={() => goToLine(p.line)}>Line {p.line}</Button> : null} {p.message}</li>
                    ))}
                  </ul>
                </div>
              )}
              {preview && job.original && preview.title !== T.squash(job.original.title) && (
                <p className="cpub-title-change">Title changed from “{job.original.title}”.</p>
              )}
              {changes && changes.count > 0 && (
                <details className="cpub-changes" id="cpub-changes">
                  <summary>{plural(changes.count, 'change')} to the original text</summary>
                  <ol>
                    {changes.hunks.map((h, i) => (
                      <li key={i}>
                        <span className="ctx">{h.before.trim()}</span> {h.removed && <del>{h.removed}</del>} {h.added && <ins>{h.added}</ins>} <span className="ctx">{h.after.trim()}</span>
                      </li>
                    ))}
                  </ol>
                  {changes.truncated && <p className="description">Only the first 50 changes are shown.</p>}
                </details>
              )}
              {changes && changes.count === 0 && <p className="cpub-same" id="cpub-same">The text is the same as the original.</p>}
            </div>
          </section>

          <section className="cpub-preview-pane">
            <div className="cpub-pane-head"><h2>Preview</h2></div>
            <div className="cpub-preview" id="cpub-preview">
              {preview ? (
                <article>
                  <h1>{preview.title}</h1>
                  {/* Server-built from escaped Markdown and passed through wp_kses_post. */}
                  <div dangerouslySetInnerHTML={{ __html: preview.html }} />
                </article>
              ) : (
                <p className="description">The preview appears here.</p>
              )}
            </div>
            {preview && (
              <p className="cpub-counts">
                {[plural(preview.counts.headings, 'heading'), plural(preview.counts.paragraphs, 'paragraph'), plural(preview.counts.lists, 'list'), preview.counts.tables && plural(preview.counts.tables, 'table'), preview.counts.images && plural(preview.counts.images, 'image')].filter(Boolean).join(' · ')}
              </p>
            )}
          </section>
        </div>
      )}

      {rejecting && (
        <Modal title="Reject this post" onRequestClose={() => setRejecting(false)}>
          <TextareaControl label="Why? The team sees this in the Posts list." value={reason} onChange={setReason} id="cpub-reason" />
          {dirty && <p className="cpub-dirty">Your unsaved changes will be discarded.</p>}
          <Button variant="primary" isDestructive onClick={reject} disabled={!reason.trim()} id="cpub-reject-confirm">Reject</Button>{' '}
          <Button variant="tertiary" onClick={() => setRejecting(false)}>Cancel</Button>
        </Modal>
      )}
    </div>
  );
}

function Guide() {
  return (
    <div className="cpub-guide" id="cpub-guide">
      <table>
        <thead><tr><th>Type this</th><th>You get</th></tr></thead>
        <tbody>
          <tr><td># Post title</td><td>The title. First line only, and only once.</td></tr>
          <tr><td>## Heading</td><td>Section heading (### smaller, #### smaller still)</td></tr>
          <tr><td>(empty line)</td><td>Starts a new paragraph. Lines without an empty line between them join up.</td></tr>
          <tr><td>- Item</td><td>Bullet list item</td></tr>
          <tr><td>1. Item</td><td>Numbered list item</td></tr>
          <tr><td>**bold** *italic*</td><td><b>bold</b> <i>italic</i></td></tr>
          <tr><td>[text](https://…)</td><td>A link. Addresses starting with https:// or www. link by themselves.</td></tr>
          <tr><td>line ending in \</td><td>A line break inside a paragraph (addresses)</td></tr>
          <tr><td>| A | B |<br />| --- | --- |<br />| 1 | 2 |</td><td>A table; the row above --- holds the column titles (leave them empty for none). Write \| for a | in a cell.</td></tr>
          <tr><td>![Description](photo.jpg "Caption")</td><td>An image on its own line: an uploaded file by name, or a web address. The description is the alt text; the caption is optional.</td></tr>
          <tr><td>\* \# \- \[</td><td>The symbol itself, not formatting. This is why you may see backslashes.</td></tr>
        </tbody>
      </table>
      <p className="description">Not supported yet: quotes, code blocks, lists inside lists, images inside lists or tables. The problems list points to any line that uses them.</p>
    </div>
  );
}
