(() => {

  // ---------------------------------------------------------------------
  // Helpers and state
  // ---------------------------------------------------------------------

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  const csrf = window.MEMOIR.csrf;

  // The search input starts readonly so password managers ignore it;
  // it becomes editable on first interaction.
  const searchInput = $('#globalSearch');
  searchInput.value = '';
  const enableSearch = () => { searchInput.readOnly = false; };
  searchInput.addEventListener('pointerdown', enableSearch, { once: true });
  searchInput.addEventListener('keydown', enableSearch, { once: true });

  let current = null;        // the note open in the editor
  let filterFolder = '';     // active folder filter ('' = all)
  let filterTag = '';        // active tag filter ('' = none)
  let pinnedOnly = false;    // "Pinned" nav filter
  let saveTimer = null;      // debounce timer for autosave
  let draftStyle = { icon: 'fa-note-sticky', color: '#6F5EE8' };
  let currentTags = [];      // tags of the open note

  async function api(action, opts = {}) {
    const headers = opts.headers || {};
    if ((opts.method || 'GET') !== 'GET') {
      headers['X-CSRF-Token'] = csrf;
    }
    if (opts.body && !(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(`api.php?action=${encodeURIComponent(action)}${opts.query || ''}`, {
      ...opts,
      headers,
      credentials: 'same-origin',
    });

    const type = res.headers.get('content-type') || '';
    const data = type.includes('application/json')
      ? await res.json()
      : { ok: false, message: 'The server returned an unexpected response' };

    if (!res.ok || data.ok === false) {
      throw new Error(data.message || 'Request failed');
    }
    return data;
  }

  const escapeHtml = s => (s ?? '').replace(/[&<>"']/g, m => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  }[m]));

  function stripHtml(html) {
    const div = document.createElement('div');
    div.innerHTML = html || '';
    return div.textContent || '';
  }

  function fmtDate(value) {
    try {
      return new Date(value.replace(' ', 'T'))
        .toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    } catch {
      return '';
    }
  }

  // ---------------------------------------------------------------------
  // Note loading, list rendering, autosave
  // ---------------------------------------------------------------------

  async function loadNote(id) {
    const d = await api('note', { query: `&id=${id}` });
    current = d.note;
    draftStyle = { icon: current.icon || 'fa-note-sticky', color: current.color || '#6F5EE8' };

    $('#emptyState').classList.add('hidden');
    $('#editorView').classList.remove('hidden');
    $('#noteTitle').value = current.title || '';
    $('#noteContent').innerHTML = current.content || '';
    $('#crumbFolder').textContent = current.folder_name || 'Unfiled';
    $('#updatedAt').textContent = `Updated ${fmtDate(current.updated_at)}`;
    $('#pinNote').classList.toggle('active', current.is_pinned == 1);
    currentTags = (current.tags || '').split(',').filter(Boolean);
    renderTagChips();
    updateWords();

    $$('.note-card').forEach(card => card.classList.toggle('active', card.dataset.id == id));

    if (window.matchMedia('(max-width: 760px)').matches) {
      document.body.classList.add('editor-open');
    }
  }

  async function refreshList() {
    const q = searchInput.value.trim();
    let query = `&q=${encodeURIComponent(q)}`;
    if (filterFolder !== '') query += `&folder=${encodeURIComponent(filterFolder)}`;
    if (filterTag !== '') query += `&tag=${encodeURIComponent(filterTag)}`;
    if (pinnedOnly) query += '&pinned=1';

    const d = await api('search', { query });
    renderNotes(d.notes);
  }

  function renderNotes(notes) {
    $('#listCount').textContent = `${notes.length} notes`;

    if (!notes.length) {
      $('#noteList').innerHTML = `<div class="list-empty"><i class="fa-regular fa-compass"></i><strong>No notes found</strong><span>Try another search or choose a different folder.</span></div>`;
      return;
    }

    $('#noteList').innerHTML = notes.map(n => `<button class="note-card ${current && current.id == n.id ? 'active' : ''}" data-id="${n.id}" data-folder="${n.folder_id ?? ''}" data-pinned="${n.is_pinned}">
    <div class="note-card-top"><i class="fa-solid ${escapeHtml(n.icon)}" style="color:${escapeHtml(n.color || '#6F5EE8')}"></i>${n.is_pinned == 1 ? '<i class="fa-solid fa-thumbtack pin-mini"></i>' : ''}</div>
    <strong>${escapeHtml(n.title)}</strong><p>${escapeHtml(stripHtml(n.content).slice(0, 115))}</p>
    <div class="note-meta"><span>${escapeHtml(n.folder_name || 'Unfiled')}${n.tags ? ' · #' + escapeHtml(n.tags).split(',').join(' #') : ''}</span><time>${fmtDate(n.updated_at)}</time></div></button>`).join('');
  }

  function queueSave() {
    if (!current) return;
    $('#saveStatus').textContent = 'Saving…';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNote, 650);
  }

  async function saveNote() {
    if (!current) return;
    const body = {
      id: current.id,
      folder_id: current.folder_id ?? '',
      title: $('#noteTitle').value,
      // Strip the zero-width spaces the markdown shortcuts use as caret anchors.
      content: $('#noteContent').innerHTML.replace(/\u200B/g, ''),
      icon: draftStyle.icon,
      color: draftStyle.color,
      tags: currentTags,
      is_pinned: current.is_pinned,
    };
    try {
      await api('save-note', { method: 'POST', body: JSON.stringify(body) });
      $('#saveStatus').textContent = 'Saved';
      await refreshList();
      refreshTagSidebar();
    } catch (e) {
      $('#saveStatus').textContent = 'Save failed';
    }
  }

  // ---------------------------------------------------------------------
  // Note actions: open, create, edit, pin, delete
  // ---------------------------------------------------------------------

  $('#noteList').addEventListener('click', e => {
    const card = e.target.closest('.note-card');
    if (card) loadNote(card.dataset.id);
  });

  $('#newNote').onclick = async () => {
    const d = await api('create-note', {
      method: 'POST',
      body: JSON.stringify({ folder_id: filterFolder || null }),
    });
    await refreshList();
    await loadNote(d.id);
    $('#noteTitle').focus();
  };

  $('#noteTitle').addEventListener('input', queueSave);
  $('#noteContent').addEventListener('input', () => {
    handleInlineShortcut();
    queueSave();
    updateWords();
  });

  $('#pinNote').onclick = () => {
    if (!current) return;
    current.is_pinned = current.is_pinned == 1 ? 0 : 1;
    $('#pinNote').classList.toggle('active', current.is_pinned == 1);
    queueSave();
  };

  $('#deleteNote').onclick = async () => {
    if (!current || !confirm('Delete this note permanently?')) return;
    await api('delete-note', { method: 'POST', body: JSON.stringify({ id: current.id }) });
    current = null;
    $('#editorView').classList.add('hidden');
    $('#emptyState').classList.remove('hidden');
    document.body.classList.remove('editor-open');
    await refreshList();
  };

  // ---------------------------------------------------------------------
  // Navigation and filtering
  // ---------------------------------------------------------------------

  function closeSidebar() {
    document.body.classList.remove('sidebar-open');
  }

  function clearFilterHighlights() {
    $$('.nav-item,.folder-item,.tag-item').forEach(x => x.classList.remove('active'));
  }

  $$('.nav-item').forEach(btn => btn.onclick = () => {
    filterFolder = btn.dataset.folder ?? '';
    filterTag = '';
    pinnedOnly = btn.dataset.pinned === '1';
    clearFilterHighlights();
    btn.classList.add('active');
    $('#listTitle').textContent = pinnedOnly ? 'Pinned' : 'All notes';
    closeSidebar();
    refreshList();
  });

  $('#folderList').addEventListener('click', e => {
    const btn = e.target.closest('.folder-item');
    if (!btn) return;
    filterFolder = btn.dataset.folder;
    filterTag = '';
    pinnedOnly = false;
    clearFilterHighlights();
    btn.classList.add('active');
    $('#listTitle').textContent = btn.querySelector('span').textContent;
    closeSidebar();
    refreshList();
  });

  // ---------------------------------------------------------------------
  // Tags: editor chips + sidebar filter
  // ---------------------------------------------------------------------

  function renderTagChips() {
    $('#tagChips').innerHTML = currentTags.map(t =>
      `<span class="tag-chip">${escapeHtml(t)}<button type="button" data-tag="${escapeHtml(t)}" aria-label="Remove tag">&times;</button></span>`
    ).join('');
  }

  function addTag(name) {
    name = name.replace(/,/g, ' ').trim().slice(0, 30);
    if (!name || currentTags.includes(name) || currentTags.length >= 8) return;
    currentTags.push(name);
    renderTagChips();
    queueSave();
  }

  async function refreshTagSidebar() {
    try {
      const d = await api('tags');
      const entries = Object.entries(d.tags);
      $('#tagSectionTitle').hidden = !entries.length;
      $('#tagList').innerHTML = entries.map(([t, c]) =>
        `<button class="tag-item ${filterTag === t ? 'active' : ''}" data-tag="${escapeHtml(t)}">#${escapeHtml(t)}<span class="count">${c}</span></button>`
      ).join('');
    } catch {}
  }

  $('#tagInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      addTag(e.target.value);
      e.target.value = '';
    } else if (e.key === 'Backspace' && !e.target.value && currentTags.length) {
      currentTags.pop();
      renderTagChips();
      queueSave();
    }
  });
  $('#tagInput').addEventListener('blur', e => {
    if (e.target.value.trim()) {
      addTag(e.target.value);
      e.target.value = '';
    }
  });

  $('#tagChips').addEventListener('click', e => {
    const btn = e.target.closest('button[data-tag]');
    if (!btn) return;
    currentTags = currentTags.filter(t => t !== btn.dataset.tag);
    renderTagChips();
    queueSave();
  });

  $('#tagList').addEventListener('click', e => {
    const btn = e.target.closest('.tag-item');
    if (!btn) return;
    filterTag = btn.dataset.tag;
    filterFolder = '';
    pinnedOnly = false;
    clearFilterHighlights();
    btn.classList.add('active');
    $('#listTitle').textContent = `#${filterTag}`;
    closeSidebar();
    refreshList();
  });

  // Debounced live search
  let searchTimer;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(refreshList, 220);
  });

  // Keyboard shortcuts: ⌘K search, ⌘N new note, ⌘S save, Esc close modals
  document.addEventListener('keydown', e => {
    const mod = e.ctrlKey || e.metaKey;
    const key = (e.key || '').toLowerCase();
    if (mod && key === 'k') {
      e.preventDefault();
      enableSearch();
      searchInput.focus();
      searchInput.select();
    }
    if (mod && key === 'n') {
      e.preventDefault();
      $('#newNote').click();
    }
    if (mod && key === 's') {
      e.preventDefault();
      clearTimeout(saveTimer);
      saveNote();
    }
    if (e.key === 'Escape') {
      $$('.modal-backdrop:not(.hidden)').forEach(closeModal);
    }
  });

  // ---------------------------------------------------------------------
  // Editor toolbar, links, images
  // ---------------------------------------------------------------------

  const editor = $('#noteContent');
  const toolbarWrap = $('.toolbar-wrap');

  function selectionInEditor() {
    const sel = getSelection();
    return sel.rangeCount > 0 && editor.contains(sel.getRangeAt(0).startContainer);
  }

  function currentBlockTag() {
    try {
      return (document.queryCommandValue('formatBlock') || '').toLowerCase();
    } catch {
      return '';
    }
  }

  // Reflect the caret's formatting on the toolbar (bold pressed, active heading, …).
  function syncToolbar() {
    if (!selectionInEditor()) return;
    $$('.toolbar [data-state]').forEach(btn => {
      try {
        btn.classList.toggle('active', document.queryCommandState(btn.dataset.state));
      } catch {}
    });
    const block = currentBlockTag();
    $$('.toolbar [data-block]').forEach(btn => btn.classList.toggle('active', block === btn.dataset.block));
    $$('.toolbar [data-cmd="formatBlock"]').forEach(btn => btn.classList.toggle('active', block === btn.dataset.value));
  }
  document.addEventListener('selectionchange', syncToolbar);

  // Pressing a toolbar button must not steal the editor's selection.
  toolbarWrap.addEventListener('mousedown', e => {
    if (e.target.closest('button')) e.preventDefault();
  });

  $$('.toolbar [data-cmd]').forEach(btn => btn.onclick = () => {
    const cmd = btn.dataset.cmd;
    let value = btn.dataset.value || null;
    // Blockquote / code block buttons toggle back to a plain paragraph.
    if (cmd === 'formatBlock' && value && currentBlockTag() === value) value = 'p';
    document.execCommand(cmd, false, value);
    editor.focus();
    syncToolbar();
    queueSave();
  });

  $$('.toolbar [data-block]').forEach(btn => btn.onclick = () => {
    const target = currentBlockTag() === btn.dataset.block ? 'p' : btn.dataset.block;
    document.execCommand('formatBlock', false, target);
    editor.focus();
    syncToolbar();
    queueSave();
  });

  $('#insertLink').onclick = () => {
    const url = prompt('Paste URL');
    if (url) document.execCommand('createLink', false, url);
  };

  // ---------------------------------------------------------------------
  // Text color & highlight
  // ---------------------------------------------------------------------

  const TEXT_COLORS = ['#1e211d', '#64748b', '#d64545', '#e0872f', '#b58a1e', '#3d8f68', '#3f7fc2', '#7a5df0'];
  const HIGHLIGHT_COLORS = ['#fdf3c0', '#fbe4d5', '#def5e5', '#def0fb', '#ece7fd', '#fde8f2', '#eceae4', '#ffffff'];

  const colorSheet = $('#colorSheet');
  let colorMode = 'fore';        // 'fore' = text color, 'hilite' = highlight
  let savedRange = null;         // selection to restore when a swatch is picked

  function saveSelection() {
    const sel = getSelection();
    if (sel.rangeCount && editor.contains(sel.getRangeAt(0).startContainer)) {
      savedRange = sel.getRangeAt(0).cloneRange();
    }
  }

  function restoreSelection() {
    if (!savedRange) return;
    const sel = getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
  }

  function openColorSheet(mode, anchorBtn) {
    if (!colorSheet.classList.contains('hidden') && colorMode === mode) {
      closeColorSheet();
      return;
    }
    colorMode = mode;
    saveSelection();

    const colors = mode === 'fore' ? TEXT_COLORS : HIGHLIGHT_COLORS;
    $('#colorSheetTitle').textContent = mode === 'fore' ? 'Text color' : 'Highlight';
    $('#colorSwatches').innerHTML = colors
      .map(c => `<button type="button" data-value="${c}" style="background:${c}" aria-label="${c}"></button>`)
      .join('');

    // Anchor the sheet below the button, clamped inside the toolbar column so
    // it opens as a sheet in view rather than running off the screen.
    colorSheet.classList.remove('hidden');
    const wrapRect = toolbarWrap.getBoundingClientRect();
    const btnRect = anchorBtn.getBoundingClientRect();
    const maxLeft = Math.max(0, wrapRect.width - colorSheet.offsetWidth);
    colorSheet.style.left = `${Math.min(Math.max(btnRect.left - wrapRect.left, 0), maxLeft)}px`;
  }

  function closeColorSheet() {
    colorSheet.classList.add('hidden');
  }

  function applyColor(value) {
    restoreSelection();
    document.execCommand('styleWithCSS', false, true);
    document.execCommand(colorMode === 'fore' ? 'foreColor' : 'hiliteColor', false, value);
    const bar = colorMode === 'fore' ? $('#textColorBar') : $('#highlightBar');
    bar.style.background = value === 'transparent' ? '' : value;
    closeColorSheet();
    editor.focus();
    queueSave();
  }

  $('#textColorBtn').onclick = e => openColorSheet('fore', e.currentTarget);
  $('#highlightBtn').onclick = e => openColorSheet('hilite', e.currentTarget);

  $('#colorSwatches').addEventListener('click', e => {
    const swatch = e.target.closest('button');
    if (swatch) applyColor(swatch.dataset.value);
  });

  $('#colorClear').onclick = () => {
    applyColor(colorMode === 'fore' ? '#1e211d' : 'transparent');
  };

  document.addEventListener('pointerdown', e => {
    if (colorSheet.classList.contains('hidden')) return;
    if (e.target.closest('#colorSheet, #textColorBtn, #highlightBtn')) return;
    closeColorSheet();
  });

  // ---------------------------------------------------------------------
  // Floating format bubble over selected text
  // ---------------------------------------------------------------------

  const bubble = $('#formatBubble');
  const editorPanel = $('.editor-panel');
  let bubbleTimer = null;

  // Clicking the bubble must not collapse the text selection it acts on.
  bubble.addEventListener('mousedown', e => e.preventDefault());

  function hideBubble() {
    bubble.classList.add('hidden');
  }

  function positionBubble() {
    const sel = getSelection();
    if (!sel.rangeCount || sel.isCollapsed) return hideBubble();
    const range = sel.getRangeAt(0);
    if (!editor.contains(range.commonAncestorContainer)) return hideBubble();
    const rect = range.getBoundingClientRect();
    if (!rect.width && !rect.height) return hideBubble();

    bubble.classList.remove('hidden');
    $$('.format-bubble [data-bstate]').forEach(btn => {
      try {
        btn.classList.toggle('active', document.queryCommandState(btn.dataset.bstate));
      } catch {}
    });

    // Float above the selection, flipping below it when there is no room,
    // and clamp horizontally so the bubble always stays inside the panel.
    const host = editorPanel.getBoundingClientRect();
    let top = rect.top - host.top - bubble.offsetHeight - 10;
    if (top < 8) top = rect.bottom - host.top + 10;
    const left = Math.min(
      Math.max(rect.left - host.left + rect.width / 2 - bubble.offsetWidth / 2, 8),
      host.width - bubble.offsetWidth - 8
    );
    bubble.style.top = `${top}px`;
    bubble.style.left = `${left}px`;
  }

  document.addEventListener('selectionchange', () => {
    clearTimeout(bubbleTimer);
    bubbleTimer = setTimeout(positionBubble, 120);
  });
  editor.addEventListener('scroll', hideBubble);

  $$('.format-bubble [data-bcmd]').forEach(btn => btn.onclick = () => {
    document.execCommand(btn.dataset.bcmd, false, null);
    positionBubble();
    syncToolbar();
    queueSave();
  });

  $('#bubbleLink').onclick = () => {
    const url = prompt('Paste URL');
    if (url) document.execCommand('createLink', false, url);
    queueSave();
  };

  $('#bubbleHighlight').onclick = () => {
    document.execCommand('styleWithCSS', false, true);
    document.execCommand('hiliteColor', false, '#fdf3c0');
    positionBubble();
    queueSave();
  };

  // ---------------------------------------------------------------------
  // Markdown typing shortcuts
  // ---------------------------------------------------------------------
  //
  // Block markers applied on Space:  #, ##, ###, -, *, 1., >
  // Block markers applied on Enter:  ``` (code block), --- (divider)
  // Inline markers applied as you type: **bold**, *italic*, ~~strike~~, `code`

  const BLOCK_SHORTCUTS = {
    '#': () => document.execCommand('formatBlock', false, 'h2'),
    '##': () => document.execCommand('formatBlock', false, 'h3'),
    '###': () => document.execCommand('formatBlock', false, 'h3'),
    '-': () => document.execCommand('insertUnorderedList'),
    '*': () => document.execCommand('insertUnorderedList'),
    '1.': () => document.execCommand('insertOrderedList'),
    '>': () => document.execCommand('formatBlock', false, 'blockquote'),
  };

  const INLINE_SHORTCUTS = [
    { tag: 'code', re: /`([^`\n]+)`$/ },
    { tag: 'strong', re: /\*\*([^*\n]+)\*\*$/ },
    { tag: 'em', re: /(^|[^*])\*([^*\n]+)\*$/ },
    { tag: 's', re: /~~([^~\n]+)~~$/ },
  ];

  // The text of the caret's block, from its start up to the caret.
  function textBeforeCaret() {
    const sel = getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return null;
    const range = sel.getRangeAt(0);
    if (!editor.contains(range.startContainer)) return null;

    let block = range.startContainer;
    while (block !== editor && block.parentNode !== editor) block = block.parentNode;

    const probe = range.cloneRange();
    probe.setStart(block === editor ? editor : block, 0);
    // Zero-width spaces (caret anchors left by inline conversions) must not
    // stop a typed marker like "###" from matching.
    return { probe, text: probe.toString().replace(/\u200B/g, ''), block };
  }

  // Remove a typed markdown marker and put the caret back on its line.
  // Deleting the marker can leave an empty block that cannot host the caret
  // (the browser snaps it to the previous line, formatting the wrong block),
  // so the emptied line gets a <br> and the caret is reselected explicitly.
  function removeMarker(found) {
    found.probe.deleteContents();
    const host = found.block;
    if (host !== editor && host.nodeType === Node.ELEMENT_NODE
        && !host.textContent && !host.querySelector('br,img')) {
      host.textContent = '';   // drop leftover empty text nodes
      host.appendChild(document.createElement('br'));
    }
    const sel = getSelection();
    found.probe.collapse(true);
    sel.removeAllRanges();
    sel.addRange(found.probe);
  }

  function inCodeContext(node) {
    const el = node.nodeType === Node.TEXT_NODE ? node.parentElement : node;
    return !!el?.closest('pre, code');
  }

  function handleBlockShortcut(e) {
    const found = textBeforeCaret();
    if (!found) return;
    const action = BLOCK_SHORTCUTS[found.text];
    if (!action || inCodeContext(getSelection().anchorNode)) return;
    e.preventDefault();
    removeMarker(found);
    action();
    syncToolbar();
    queueSave();
  }

  function handleEnterShortcut(e) {
    const found = textBeforeCaret();
    if (!found || inCodeContext(getSelection().anchorNode)) return;
    if (found.text === '```') {
      e.preventDefault();
      removeMarker(found);
      document.execCommand('formatBlock', false, 'pre');
      syncToolbar();
      queueSave();
    } else if (found.text === '---' || found.text === '***') {
      e.preventDefault();
      removeMarker(found);
      document.execCommand('insertHTML', false, '<hr><p><br></p>');
      queueSave();
    }
  }

  function handleInlineShortcut() {
    const sel = getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return false;
    const node = sel.anchorNode;
    if (node.nodeType !== Node.TEXT_NODE || !editor.contains(node) || inCodeContext(node)) return false;

    const upto = node.textContent.slice(0, sel.anchorOffset);
    for (const { tag, re } of INLINE_SHORTCUTS) {
      const m = re.exec(upto);
      if (!m) continue;
      const content = m[m.length - 1];
      const start = m.index + (m.length === 3 ? m[1].length : 0);

      const range = document.createRange();
      range.setStart(node, start);
      range.setEnd(node, sel.anchorOffset);
      range.deleteContents();

      const el = document.createElement(tag);
      el.textContent = content;
      range.insertNode(el);

      // Land the caret in a zero-width space after the element so typing
      // continues unformatted (the marker is stripped again on save).
      const tail = document.createTextNode('\u200B');
      el.after(tail);
      const caret = document.createRange();
      caret.setStart(tail, 1);
      caret.collapse(true);
      sel.removeAllRanges();
      sel.addRange(caret);
      return true;
    }
    return false;
  }

  editor.addEventListener('keydown', e => {
    if (e.key === ' ') handleBlockShortcut(e);
    if (e.key === 'Enter') handleEnterShortcut(e);
  });

  $('#insertImage').onclick = () => $('#imageInput').click();
  $('#imageInput').onchange = e => e.target.files[0] && uploadImage(e.target.files[0]);

  // Images can also be pasted or dropped straight into the editor.
  $('#noteContent').addEventListener('paste', e => {
    const item = [...(e.clipboardData?.items || [])].find(i => i.type.startsWith('image/'));
    if (item) {
      e.preventDefault();
      const file = item.getAsFile();
      if (file) uploadImage(file);
    }
  });

  $('#noteContent').addEventListener('dragover', e => { e.preventDefault(); });

  $('#noteContent').addEventListener('drop', e => {
    const file = [...(e.dataTransfer?.files || [])].find(f => f.type.startsWith('image/'));
    if (file) {
      e.preventDefault();
      uploadImage(file);
    }
  });

  async function uploadImage(file) {
    const fd = new FormData();
    fd.append('image', file);
    try {
      const d = await api('upload', { method: 'POST', body: fd });
      document.execCommand('insertImage', false, d.url);
      queueSave();
    } catch (e) {
      alert(e.message);
    }
  }

  function updateWords() {
    const text = $('#noteContent').innerText.trim();
    $('#wordCount').textContent = `${text ? text.split(/\s+/).length : 0} words`;
  }

  // ---------------------------------------------------------------------
  // Modals
  // ---------------------------------------------------------------------

  function openModal(id) {
    const modal = $(id);
    modal.classList.remove('hidden');
    setTimeout(() => modal.querySelector('input:not([type="hidden"]),button')?.focus(), 0);
  }

  function closeModal(modal) {
    modal.classList.add('hidden');
  }

  $$('[data-close]').forEach(btn => btn.onclick = () => closeModal(btn.closest('.modal-backdrop')));
  $$('.modal-backdrop').forEach(m => m.addEventListener('click', e => {
    if (e.target === m) closeModal(m);
  }));

  // New folder modal
  let folderIcon = 'fa-folder';
  let folderColor = '#6F5EE8';

  $('#addFolder').onclick = () => openModal('#folderModal');

  $('#folderIcons').onclick = e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    folderIcon = btn.dataset.icon;
    $$('#folderIcons button').forEach(x => x.classList.remove('selected'));
    btn.classList.add('selected');
  };

  $('#folderColors').onclick = e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    folderColor = btn.dataset.color;
    $$('#folderColors button').forEach(x => x.classList.remove('selected'));
    btn.classList.add('selected');
  };

  $('#saveFolder').onclick = async () => {
    const name = $('#folderName').value.trim();
    if (!name) return;
    const d = await api('folder', {
      method: 'POST',
      body: JSON.stringify({ name, icon: folderIcon, color: folderColor }),
    });
    $('#folderList').insertAdjacentHTML('beforeend', `<button class="folder-item" data-folder="${d.id}"><i class="fa-solid ${d.icon}" style="color:${d.color}"></i><span>${escapeHtml(d.name)}</span><span class="count">0</span></button>`);
    $('#folderName').value = '';
    closeModal($('#folderModal'));
  };

  // Note appearance modal
  $('#noteStyle').onclick = () => openModal('#styleModal');

  $('#noteIcons').onclick = e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    draftStyle.icon = btn.dataset.icon;
    queueSave();
    $$('#noteIcons button').forEach(x => x.classList.remove('selected'));
    btn.classList.add('selected');
  };

  $('#noteColors').onclick = e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    draftStyle.color = btn.dataset.color;
    queueSave();
    $$('#noteColors button').forEach(x => x.classList.remove('selected'));
    btn.classList.add('selected');
  };

  // ---------------------------------------------------------------------
  // Sidebar toggles and settings
  // ---------------------------------------------------------------------

  $('#settingsBtn').onclick = () => openModal('#settingsModal');
  $('#whatsNewBtn').onclick = () => openModal('#whatsNewModal');
  $('#collapseSidebar').onclick = () => document.body.classList.add('sidebar-open');
  $('#closeSidebar').onclick = closeSidebar;
  $('#mobileScrim').onclick = closeSidebar;
  $('#backToList').onclick = () => document.body.classList.remove('editor-open');

  $('#saveSettings').onclick = async () => {
    const body = {
      app_name: $('#setAppName').value,
      smtp_host: $('#setSmtpHost').value,
      smtp_port: $('#setSmtpPort').value,
      smtp_security: $('#setSmtpSecurity').value,
      smtp_user: $('#setSmtpUser').value,
      smtp_pass: $('#setSmtpPass').value,
      smtp_from: $('#setSmtpFrom').value,
    };
    await api('settings', { method: 'POST', body: JSON.stringify(body) });
    closeModal($('#settingsModal'));
    location.reload();
  };

})();
