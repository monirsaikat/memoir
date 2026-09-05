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
  let trashView = false;     // showing the trash instead of live notes
  let saveTimer = null;      // debounce timer for autosave
  let savePromise = null;    // active save, awaited before history opens
  let draftStyle = { icon: 'fa-note-sticky', color: '#6F5EE8' };
  let currentTags = [];      // tags of the open note
  let sortMode = 'updated';  // note list order: updated | created | title
  let lastSearchText = '';
  const noteCache = new Map(
    (window.MEMOIR.initialNotes || []).map(note => [String(note.id), note])
  );
  const referenceCache = new Map();
  let listRequestSequence = 0;
  let noteRequestSequence = 0;
  const advancedFilters = { scope: 'all', pinned: '', state: '', after: '', before: '' };
  try {
    const stored = localStorage.getItem('memoir-sort');
    if (['updated', 'created', 'title'].includes(stored)) sortMode = stored;
  } catch {}

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

  function referenceExcerpt(content, needle) {
    const text = stripHtml(content).replace(/\s+/g, ' ').trim();
    if (!text) return '';
    const index = needle ? text.toLocaleLowerCase().indexOf(needle.toLocaleLowerCase()) : -1;
    if (index < 0) return text.slice(0, 150) + (text.length > 150 ? '…' : '');
    const start = Math.max(0, index - 72);
    const end = Math.min(text.length, index + needle.length + 72);
    return `${start ? '…' : ''}${text.slice(start, end)}${end < text.length ? '…' : ''}`;
  }

  function localReferencesFor(note) {
    const key = String(note.id);
    const targetTitle = String(note.title || '').trim();
    const linked = [];
    const unlinked = [];
    [...noteCache.values()].forEach(source => {
      if (source.deleted_at || String(source.id) === key || source._content_cached === false) return;
      const content = String(source.content || '');
      const hasLink = content.includes(`data-note-link="${key}"`);
      if (hasLink) {
        linked.push({
          id: source.id,
          title: source.title,
          folder_name: source.folder_name,
          excerpt: referenceExcerpt(content, targetTitle),
        });
        return;
      }
      if (targetTitle.length < 3) return;
      const plain = stripHtml(content).replace(/\s+/g, ' ');
      const foldedPlain = plain.toLocaleLowerCase();
      const foldedTitle = targetTitle.toLocaleLowerCase();
      let position = foldedPlain.indexOf(foldedTitle);
      let exactMention = false;
      while (position >= 0) {
        const before = position > 0 ? plain[position - 1] : '';
        const after = plain[position + targetTitle.length] || '';
        if (!/[\p{L}\p{N}]/u.test(before) && !/[\p{L}\p{N}]/u.test(after)) {
          exactMention = true;
          break;
        }
        position = foldedPlain.indexOf(foldedTitle, position + foldedTitle.length);
      }
      if (exactMention) {
        unlinked.push({
          id: source.id,
          title: source.title,
          folder_name: source.folder_name,
          excerpt: referenceExcerpt(content, targetTitle),
        });
      }
    });
    return { linked: linked.slice(0, 20), unlinked: unlinked.slice(0, 20) };
  }

  function renderReferences(references = {}) {
    const linked = Array.isArray(references) ? references : (references.linked || []);
    const unlinked = Array.isArray(references) ? [] : (references.unlinked || []);
    const renderItems = items => items.map(item =>
      `<button type="button" class="reference-item" data-id="${item.id}">
        <span class="reference-item-top">
          <strong>${escapeHtml(item.title || 'Untitled note')}</strong>
          <span>${escapeHtml(item.folder_name || 'Unfiled')}</span>
        </span>
        ${item.excerpt ? `<span class="reference-excerpt">${escapeHtml(item.excerpt)}</span>` : ''}
      </button>`
    ).join('');

    $('#linkedReferenceCount').textContent = linked.length;
    $('#unlinkedReferenceCount').textContent = unlinked.length;
    $('#backlinkList').innerHTML = renderItems(linked);
    $('#unlinkedMentionList').innerHTML = renderItems(unlinked);
    $('#linkedReferences').classList.toggle('hidden', !linked.length);
    $('#unlinkedReferences').classList.toggle('hidden', !unlinked.length);
    $('#backlinks').classList.toggle('hidden', !linked.length && !unlinked.length);
  }

  function renderNote(note, references = {}, push = true) {
    current = note;
    noteCache.set(String(note.id), note);

    draftStyle = {
      icon: current.icon || 'fa-note-sticky',
      color: current.color || '#6F5EE8'
    };

    $('#emptyState').classList.add('hidden');
    $('#editorView').classList.remove('hidden');

    $('#noteTitle').value = current.title || '';
    $('#noteContent').innerHTML = current.content || '';
    $('#crumbFolder').textContent = current.folder_name || 'Unfiled';

    $('#updatedAt').textContent =
      `Updated ${fmtDate(current.updated_at)}`;

    $('#pinNote').classList.toggle(
      'active',
      current.is_pinned == 1
    );

    $('#shareNote').classList.toggle(
      'active',
      !!current.share_token
    );

    currentTags = (current.tags || '')
      .split(',')
      .filter(Boolean);

    renderTagChips();

    setEditorReadOnly(!!current.deleted_at);

    updateWords();
    scheduleHighlightCode();

    renderReferences(references);

    $$('.note-card').forEach(card => {
      card.classList.toggle(
        'active',
        card.dataset.id == current.id
      );
    });

    if (window.matchMedia('(max-width: 760px)').matches) {
      document.body.classList.add('editor-open');
    }

    syncUrl(push);
  }

  function refreshReferences(key, requestSequence, delay = 0) {
    setTimeout(async () => {
      try {
        const data = await api('references', { query: `&id=${encodeURIComponent(key)}` });
        const references = data.references || data.backlinks || [];
        referenceCache.set(String(key), references);
        if (requestSequence === noteRequestSequence && String(current?.id) === String(key)) {
          renderReferences(references);
        }
      } catch {}
    }, delay);
  }


  async function loadNote(id, push = true) {
    // Capture a pending edit before switching current to another note. The
    // request may finish later; serializing the old draft happens immediately.
    if (saveTimer) saveNote();

    const key = String(id);
    const cached = noteCache.get(key);
    const hasCachedContent = cached && cached._content_cached !== false;
    const requestSequence = ++noteRequestSequence;

    // index.php already loaded the first 100 notes. Reusing that data makes a
    // card click render in the same frame instead of waiting for shared-hosting
    // latency. Reference discovery can then run independently after paint.
    if (hasCachedContent) {
      const localReferences = referenceCache.get(key) || localReferencesFor(cached);
      renderNote(cached, localReferences, push);
      if (window.MEMOIR.initialActiveComplete && window.MEMOIR.initialContentComplete) {
        referenceCache.set(key, localReferences);
        return;
      }
      // Reference discovery is intentionally lazy so it never delays opening.
      refreshReferences(key, requestSequence, 150);
      return;
    }

    const d = await api('note', { query: `&id=${encodeURIComponent(id)}` });
    d.note._content_cached = true;
    d.note._server_fresh = true;
    noteCache.set(key, d.note);
    if (requestSequence !== noteRequestSequence || String(current?.id) !== key) return;
    renderNote(d.note, referenceCache.get(key) || {}, push);
    refreshReferences(key, requestSequence, 0);
  }

  // Trashed notes open read-only, with a banner offering restore/destroy.
  function setEditorReadOnly(readOnly) {
    $('#noteContent').contentEditable = readOnly ? 'false' : 'true';
    $('#noteTitle').readOnly = readOnly;
    $('#editorView').classList.toggle('read-only', readOnly);
    $('#trashBanner').classList.toggle('hidden', !readOnly);
  }

  function closeEditor() {
    current = null;
    $('#editorView').classList.add('hidden');
    $('#emptyState').classList.remove('hidden');
    document.body.classList.remove('editor-open');
    setEditorReadOnly(false);
  }

  function cachedList() {
    // Trash is not part of the initial page payload and advanced query
    // operators need the server parser, so those views stay server-backed.
    const q = searchInput.value.trim();
    if (trashView || ['trash', 'all'].includes(advancedFilters.state) || /(?:^|\s)(?:tag|folder|is|before|after|in):/i.test(q)) {
      return null;
    }
    if (q && !window.MEMOIR.initialContentComplete) return null;

    const needle = q.toLocaleLowerCase();
    const after = advancedFilters.after ? new Date(`${advancedFilters.after}T00:00:00`).getTime() : null;
    const before = advancedFilters.before ? new Date(`${advancedFilters.before}T00:00:00`).getTime() : null;
    const rows = [...noteCache.values()].filter(note => {
      if (note.deleted_at) return false;
      if (filterFolder !== '' && String(note.folder_id ?? '') !== String(filterFolder)) return false;
      if (filterTag !== '' && !(note.tags || '').split(',').includes(filterTag)) return false;
      const wantedPin = advancedFilters.pinned !== '' ? advancedFilters.pinned : (pinnedOnly ? '1' : '');
      if (wantedPin !== '' && String(Number(note.is_pinned)) !== wantedPin) return false;
      const updated = new Date(String(note.updated_at || '').replace(' ', 'T')).getTime();
      if (after && updated < after) return false;
      if (before && updated >= before) return false;
      if (!needle) return true;
      const fields = advancedFilters.scope === 'all'
        ? [note.title, note.content, note.tags]
        : [note[advancedFilters.scope]];
      return fields.some(value => stripHtml(String(value || '')).toLocaleLowerCase().includes(needle));
    });

    rows.sort((a, b) => {
      const pinned = Number(b.is_pinned) - Number(a.is_pinned);
      if (pinned) return pinned;
      if (sortMode === 'title') return String(a.title).localeCompare(String(b.title));
      const field = sortMode === 'created' ? 'created_at' : 'updated_at';
      return String(b[field]).localeCompare(String(a[field]));
    });
    return rows.slice(0, 100);
  }

  async function refreshList() {
    const requestSequence = ++listRequestSequence;
    const q = searchInput.value.trim();
    let query = `&q=${encodeURIComponent(q)}`;
    if (filterFolder !== '') query += `&folder=${encodeURIComponent(filterFolder)}`;
    if (filterTag !== '') query += `&tag=${encodeURIComponent(filterTag)}`;
    if (pinnedOnly) query += '&pinned=1';
    if (trashView) query += '&trash=1';
    if (sortMode !== 'updated') query += `&sort=${sortMode}`;
    if (advancedFilters.scope !== 'all') query += `&scope=${encodeURIComponent(advancedFilters.scope)}`;
    if (advancedFilters.pinned !== '') query += `&pinned=${advancedFilters.pinned}`;
    if (advancedFilters.state !== '') query += `&state=${advancedFilters.state}`;
    if (advancedFilters.after) query += `&after=${advancedFilters.after}`;
    if (advancedFilters.before) query += `&before=${advancedFilters.before}`;

    const immediate = cachedList();
    if (immediate) {
      lastSearchText = q;
      renderNotes(immediate);
      renderActiveSearchFilters();
      syncUrl();
    }

    // With fewer than 100 active notes the initial payload is the complete
    // dataset. Empty-query folder/tag/pin/sort navigation is exact locally and
    // needs no API round trip at all.
    if (immediate && window.MEMOIR.initialActiveComplete && !q && !trashView
        && !['trash', 'all'].includes(advancedFilters.state)) {
      return;
    }

    const d = await api('search', { query });
    if (requestSequence !== listRequestSequence) return;
    lastSearchText = d.query_text || '';
    d.notes.forEach(note => noteCache.set(String(note.id), note));
    renderNotes(d.notes);
    renderActiveSearchFilters();
    syncUrl();
  }

  const escapeRegExp = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  // Escape text for HTML while wrapping search-term matches in <mark>.
  function markMatches(text, q) {
    const tokens = (q || '').split(/\s+/).filter(Boolean).map(escapeRegExp);
    if (!tokens.length) return escapeHtml(text);
    const parts = text.split(new RegExp(`(${tokens.join('|')})`, 'ig'));
    return parts.map((part, i) => i % 2 ? `<mark>${escapeHtml(part)}</mark>` : escapeHtml(part)).join('');
  }

  // Preview snippet centered on the first search match instead of the start.
  function previewSnippet(content, q) {
    const text = stripHtml(content);
    if (!q) return { text: text.slice(0, 115), leading: false };
    const idx = text.toLowerCase().indexOf(q.split(/\s+/)[0]?.toLowerCase() || '');
    if (idx <= 40) return { text: text.slice(0, 115), leading: false };
    return { text: text.slice(idx - 30, idx + 85), leading: true };
  }

  function renderNotes(notes) {
    notes.forEach(note => noteCache.set(String(note.id), note));
    $('#listCount').textContent = `${notes.length} notes`;

    if (!notes.length) {
      $('#noteList').innerHTML = `<div class="list-empty"><i class="fa-regular fa-compass"></i><strong>No notes found</strong><span>Try another search or choose a different folder.</span></div>`;
      return;
    }

    const q = lastSearchText;
    $('#noteList').innerHTML = notes.map(n => {
      const snip = !q && typeof n._preview === 'string'
        ? { text: n._preview, leading: false }
        : previewSnippet(n.content, q);
      return `<button class="note-card ${current && current.id == n.id ? 'active' : ''}${typeof selectedIds !== 'undefined' && selectedIds.has(String(n.id)) ? ' selected' : ''}" data-id="${n.id}" data-folder="${n.folder_id ?? ''}" data-pinned="${n.is_pinned}">
    <div class="note-card-top"><i class="fa-solid ${escapeHtml(n.icon)}" style="color:${escapeHtml(!n.color || n.color.toUpperCase() === '#FFFFFF' ? '#6F5EE8' : n.color)}"></i>${n.is_pinned == 1 ? '<i class="fa-solid fa-thumbtack pin-mini"></i>' : ''}</div>
    <strong>${markMatches(n.title, q)}</strong><p>${snip.leading ? '…' : ''}${markMatches(snip.text, q)}</p>
    <div class="note-meta"><span>${escapeHtml(n.folder_name || 'Unfiled')}${n.tags ? ' · #' + escapeHtml(n.tags).split(',').join(' #') : ''}</span><time>${fmtDate(n.updated_at)}</time></div></button>`;
    }).join('');
  }

  function queueSave() {
    if (!current) return;
    $('#saveStatus').textContent = 'Saving…';
    clearTimeout(saveTimer);
    // Serializing rich text requires cloning its DOM. Give large notes a
    // longer quiet period so that work does not land between keystrokes.
    const delay = String(current.content || '').length > 30000 ? 1200 : 650;
    saveTimer = setTimeout(saveNote, delay);
  }

  // Serialize the note body for saving: drop syntax-highlight markup from
  // code blocks (recreated on load) and the markdown caret anchors.
  function serializeContent() {
    const clone = $('#noteContent').cloneNode(true);
    clone.querySelectorAll('pre').forEach(pre => {
      if (pre.querySelector('span')) pre.textContent = pre.textContent;
    });
    return clone.innerHTML.replace(/\u200B/g, '');
  }

  async function saveNote() {
    if (!current) return;
    clearTimeout(saveTimer);
    saveTimer = null;
    const body = {
      id: current.id,
      folder_id: current.folder_id ?? '',
      title: $('#noteTitle').value,
      content: serializeContent(),
      icon: draftStyle.icon,
      color: draftStyle.color,
      tags: currentTags,
      is_pinned: current.is_pinned,
    };
    const savingId = String(current.id);
    Object.assign(current, body, {
      tags: currentTags.join(','),
      updated_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
    });
    noteCache.set(savingId, current);
    const request = api('save-note', { method: 'POST', body: JSON.stringify(body) });
    savePromise = request;
    try {
      await request;

      referenceCache.clear();
      if (String(current?.id) === savingId) {
        $('#saveStatus').textContent = 'Saved';
        renderReferences(localReferencesFor(current));
        if (!window.MEMOIR.initialContentComplete) {
          refreshReferences(savingId, noteRequestSequence, 0);
        }
      }

      refreshList().catch(() => {});
    } catch (e) {
      if (String(current?.id) === savingId) $('#saveStatus').textContent = 'Save failed';
    } finally {
      if (savePromise === request) savePromise = null;
    }
  }

  // ---------------------------------------------------------------------
  // Note actions: open, create, edit, pin, delete
  // ---------------------------------------------------------------------

  // ---------------------------------------------------------------------
  // Bulk selection & delete
  // ---------------------------------------------------------------------

  let selectMode = false;
  const selectedIds = new Set();

  function updateBulkBar() {
    $('#bulkCount').textContent = `${selectedIds.size} selected`;
    $('#bulkDelete').disabled = !selectedIds.size;
  }

  function setSelectMode(on) {
    selectMode = on;
    selectedIds.clear();
    $('#noteList').classList.toggle('select-mode', on);
    $('#bulkBar').classList.toggle('hidden', !on);
    $('#selectModeBtn').classList.toggle('active', on);
    $$('.note-card').forEach(card => card.classList.remove('selected'));
    updateBulkBar();
  }

  // In select mode (or with Ctrl/Cmd held) clicking a card toggles its
  // selection instead of opening it.
  $('#noteList').addEventListener('click', e => {
    const card = e.target.closest('.note-card');
    if (!card) return;
    if (selectMode || e.ctrlKey || e.metaKey) {
      if (!selectMode) setSelectMode(true);
      const id = card.dataset.id;
      if (selectedIds.has(id)) {
        selectedIds.delete(id);
        card.classList.remove('selected');
      } else {
        selectedIds.add(id);
        card.classList.add('selected');
      }
      updateBulkBar();
      return;
    }
    loadNote(card.dataset.id);
  });

  $('#selectModeBtn').onclick = () => setSelectMode(!selectMode);
  $('#bulkCancel').onclick = () => setSelectMode(false);

  $('#bulkSelectAll').onclick = () => {
    $$('.note-card').forEach(card => {
      selectedIds.add(card.dataset.id);
      card.classList.add('selected');
    });
    updateBulkBar();
  };

  $('#bulkDelete').onclick = async () => {
    if (!selectedIds.size) return;
    const n = selectedIds.size;
    if (trashView) {
      // Out of the trash there is no way back.
      if (!confirm(`Delete ${n} note${n > 1 ? 's' : ''} forever? This cannot be undone.`)) return;
      await api('destroy-notes', { method: 'POST', body: JSON.stringify({ ids: [...selectedIds] }) });
    } else {
      await api('delete-notes', { method: 'POST', body: JSON.stringify({ ids: [...selectedIds] }) });
    }
    if (current && selectedIds.has(String(current.id))) closeEditor();
    setSelectMode(false);
    await refreshList();
  };

  $('#bulkRestore').onclick = async () => {
    if (!selectedIds.size) return;
    await api('restore-notes', { method: 'POST', body: JSON.stringify({ ids: [...selectedIds] }) });
    if (current && selectedIds.has(String(current.id))) closeEditor();
    setSelectMode(false);
    await refreshList();
  };

  $('#newNote').onclick = async () => {
    try {
      const d = await api('create-note', {
        method: 'POST',
        body: JSON.stringify({
          folder_id: filterFolder || null
        }),
      });

      // Immediately open the note returned by create-note.
      renderNote(
        d.note,
        d.references || d.backlinks || [],
        true
      );

      $('#noteTitle').focus();

      // Refresh secondary UI without blocking the editor.
      refreshList().catch(() => {}); 

    } catch (err) {
      console.error('Could not create note:', err);
    }
  };

  $('#noteTitle').addEventListener('input', queueSave);
  $('#noteContent').addEventListener('input', () => {
    handleInlineShortcut();
    updateWikiMenu();
    queueSave();
    updateWords(false);
  });

  $('#pinNote').onclick = () => {
    if (!current) return;
    current.is_pinned = current.is_pinned == 1 ? 0 : 1;
    $('#pinNote').classList.toggle('active', current.is_pinned == 1);
    queueSave();
  };

  // Deleting moves the note to the trash (recoverable), so no confirm.
  $('#deleteNote').onclick = async () => {
    if (!current) return;
    await api('delete-note', { method: 'POST', body: JSON.stringify({ id: current.id }) });
    closeEditor();
    await refreshList();
  };

  $('#restoreNote').onclick = async () => {
    if (!current) return;
    await api('restore-notes', { method: 'POST', body: JSON.stringify({ ids: [current.id] }) });
    const id = current.id;
    await refreshList();
    await loadNote(id, false);
  };

  $('#destroyNote').onclick = async () => {
    if (!current || !confirm('Delete this note forever? This cannot be undone.')) return;
    await api('destroy-notes', { method: 'POST', body: JSON.stringify({ ids: [current.id] }) });
    closeEditor();
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

  // Entering or leaving the trash reshapes the bulk actions.
  function applyTrashModeUi() {
    $('#bulkRestore').classList.toggle('hidden', !trashView);
    $('#bulkDeleteLabel').textContent = trashView ? 'Delete forever' : 'Delete';
  }

  $$('.nav-item').forEach(btn => btn.onclick = () => {
    filterFolder = btn.dataset.folder ?? '';
    filterTag = '';
    pinnedOnly = btn.dataset.pinned === '1';
    trashView = btn.dataset.trash === '1';
    applyTrashModeUi();
    clearFilterHighlights();
    btn.classList.add('active');
    $('#listTitle').textContent = trashView ? 'Trash' : (pinnedOnly ? 'Pinned' : 'All notes');
    closeSidebar();
    syncUrl(true);
    refreshList();
  });

  $('#folderList').addEventListener('click', e => {
    const btn = e.target.closest('.folder-item');
    if (!btn) return;
    filterFolder = btn.dataset.folder;
    filterTag = '';
    pinnedOnly = false;
    trashView = false;
    applyTrashModeUi();
    clearFilterHighlights();
    btn.classList.add('active');
    $('#listTitle').textContent = btn.querySelector('span').textContent;
    closeSidebar();
    syncUrl(true);
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

  // Refresh every sidebar count in one round trip: tags, folders, all, trash.
  async function refreshSidebar() {
    try {
      const d = await api('sidebar');
      const entries = Object.entries(d.tags);
      $('#tagSectionTitle').hidden = !entries.length;
      $('#tagList').innerHTML = entries.map(([t, c]) =>
        `<button class="tag-item ${filterTag === t ? 'active' : ''}" data-tag="${escapeHtml(t)}">#${escapeHtml(t)}<span class="count">${c}</span></button>`
      ).join('');
      Object.entries(d.folders).forEach(([id, c]) => {
        const count = $(`.folder-item[data-folder="${CSS.escape(id)}"] .count`);
        if (count) count.textContent = c;
      });
      $('.nav-item[data-folder=""] .count').textContent = d.all;
      $('#trashCount').textContent = d.trash;
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
    trashView = false;
    applyTrashModeUi();
    clearFilterHighlights();
    btn.classList.add('active');
    $('#listTitle').textContent = `#${filterTag}`;
    closeSidebar();
    syncUrl(true);
    refreshList();
  });

  // Debounced live search
  let searchTimer;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(refreshList, 220);
  });

  function syncAdvancedFilterControls() {
    $('#searchScope').value = advancedFilters.scope;
    $('#searchPinned').value = advancedFilters.pinned;
    $('#searchState').value = advancedFilters.state;
    $('#searchAfter').value = advancedFilters.after;
    $('#searchBefore').value = advancedFilters.before;
  }

  function renderActiveSearchFilters() {
    const labels = [];
    if (advancedFilters.scope !== 'all') labels.push(`In: ${advancedFilters.scope}`);
    if (advancedFilters.pinned === '1') labels.push('Pinned');
    if (advancedFilters.pinned === '0') labels.push('Not pinned');
    if (advancedFilters.state === 'active') labels.push('Active notes');
    if (advancedFilters.state === 'trash') labels.push('Trash');
    if (advancedFilters.state === 'all') labels.push('Active + Trash');
    if (advancedFilters.after) labels.push(`After ${advancedFilters.after}`);
    if (advancedFilters.before) labels.push(`Before ${advancedFilters.before}`);
    const wrap = $('#activeSearchFilters');
    wrap.classList.toggle('hidden', !labels.length);
    wrap.innerHTML = labels.length
      ? labels.map(label => `<button type="button" title="Clear advanced filters">${escapeHtml(label)} &times;</button>`).join('')
      : '';
    $('#searchFilterBtn').classList.toggle('active', !!labels.length);
  }

  function clearAdvancedFilters(refresh = true) {
    Object.assign(advancedFilters, { scope: 'all', pinned: '', state: '', after: '', before: '' });
    syncAdvancedFilterControls();
    renderActiveSearchFilters();
    if (refresh) refreshList();
  }

  $('#searchFilterBtn').onclick = e => {
    e.stopPropagation();
    const panel = $('#searchFilterPanel');
    panel.classList.toggle('hidden');
    syncAdvancedFilterControls();
  };
  $('#searchFilterPanel').addEventListener('click', e => e.stopPropagation());
  document.addEventListener('click', () => $('#searchFilterPanel').classList.add('hidden'));
  $('#applySearchFilters').onclick = () => {
    advancedFilters.scope = $('#searchScope').value;
    advancedFilters.pinned = $('#searchPinned').value;
    advancedFilters.state = $('#searchState').value;
    advancedFilters.after = $('#searchAfter').value;
    advancedFilters.before = $('#searchBefore').value;
    $('#searchFilterPanel').classList.add('hidden');
    refreshList();
  };
  $('#clearSearchFilters').onclick = () => clearAdvancedFilters();
  $('#activeSearchFilters').onclick = () => clearAdvancedFilters();

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
    if (mod && key === 'p') {
      e.preventDefault();
      openPalette();
    }
    if (mod && key === 's') {
      e.preventDefault();
      clearTimeout(saveTimer);
      saveNote();
    }
    if (e.key === 'Escape') {
      $$('.modal-backdrop:not(.hidden)').forEach(closeModal);
      if (selectMode) setSelectMode(false);
      closeMiniMenus();
      closePalette();
      closeWikiMenu();
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
    $$('.toolbar [data-cmd="formatBlock"]').forEach(btn => btn.classList.toggle('active', block === btn.dataset.value));
    const heading = /^h([1-6])$/.exec(block);
    $('#headingLabel').textContent = heading ? `H${heading[1]}` : 'H';
    $('#headingBtn').classList.toggle('active', !!heading);
  }
  let toolbarFrame = 0;
  document.addEventListener('selectionchange', () => {
    if (toolbarFrame) return;
    toolbarFrame = requestAnimationFrame(() => {
      toolbarFrame = 0;
      syncToolbar();
    });
  });

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

  // Heading dropdown: pick H1–H6 or normal text.
  const headingSheet = $('#headingSheet');

  $('#headingBtn').onclick = e => {
    if (!headingSheet.classList.contains('hidden')) {
      headingSheet.classList.add('hidden');
      return;
    }
    saveSelection();
    const block = currentBlockTag();
    $$('#headingSheet button').forEach(btn =>
      btn.classList.toggle('active', btn.dataset.h === (block || 'p')));

    headingSheet.classList.remove('hidden');
    const wrapRect = toolbarWrap.getBoundingClientRect();
    const btnRect = e.currentTarget.getBoundingClientRect();
    const maxLeft = Math.max(0, wrapRect.width - headingSheet.offsetWidth);
    headingSheet.style.left = `${Math.min(Math.max(btnRect.left - wrapRect.left, 0), maxLeft)}px`;
  };

  headingSheet.addEventListener('click', e => {
    const btn = e.target.closest('button[data-h]');
    if (!btn) return;
    restoreSelection();
    const target = btn.dataset.h === currentBlockTag() ? 'p' : btn.dataset.h;
    document.execCommand('formatBlock', false, target);
    headingSheet.classList.add('hidden');
    editor.focus();
    syncToolbar();
    queueSave();
  });

  document.addEventListener('pointerdown', e => {
    if (headingSheet.classList.contains('hidden')) return;
    if (e.target.closest('#headingSheet, #headingBtn')) return;
    headingSheet.classList.add('hidden');
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
    bubbleTimer = setTimeout(() => {
      positionBubble();
      positionTableMenu();
      highlightCode();
    }, 120);
  });
  editor.addEventListener('scroll', () => {
    hideBubble();
    hideTableMenu();
  });

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
  // Task lists (checklists)
  // ---------------------------------------------------------------------

  function closestChecklistLi(node) {
    const el = node && (node.nodeType === Node.TEXT_NODE ? node.parentElement : node);
    const li = el?.closest('ul.checklist > li');
    return li && editor.contains(li) ? li : null;
  }

  function markChecklist(ul) {
    ul.classList.add('checklist');
    [...ul.children].forEach(li => {
      if (li.dataset.checked !== '1') li.dataset.checked = '0';
    });
  }

  // Toggle the caret's list between checklist and plain bullets,
  // creating a new list when the caret is not in one.
  function toggleChecklist() {
    const sel = getSelection();
    if (!sel.rangeCount) return;
    let el = sel.anchorNode;
    el = el.nodeType === Node.TEXT_NODE ? el.parentElement : el;
    const ul = el?.closest('ul');

    if (ul && editor.contains(ul)) {
      if (ul.classList.contains('checklist')) {
        ul.classList.remove('checklist');
        [...ul.children].forEach(li => delete li.dataset.checked);
      } else {
        markChecklist(ul);
      }
    } else {
      document.execCommand('insertUnorderedList');
      let el2 = getSelection().anchorNode;
      el2 = el2 && (el2.nodeType === Node.TEXT_NODE ? el2.parentElement : el2);
      const created = el2?.closest('ul');
      if (created && editor.contains(created)) markChecklist(created);
    }
    queueSave();
  }

  $('#checklistBtn').onclick = () => {
    toggleChecklist();
    editor.focus();
  };

  // Clicking the checkbox zone (the left edge of the item) toggles it.
  editor.addEventListener('click', e => {
    const li = e.target.closest('ul.checklist > li');
    if (!li || !editor.contains(li)) return;
    if (e.clientX <= li.getBoundingClientRect().left + 24) {
      li.dataset.checked = li.dataset.checked === '1' ? '0' : '1';
      queueSave();
    }
  });

  // ---------------------------------------------------------------------
  // Tables
  // ---------------------------------------------------------------------

  const tableMenu = $('#tableMenu');

  function currentCell() {
    const sel = getSelection();
    if (!sel.rangeCount) return null;
    let el = sel.anchorNode;
    el = el && (el.nodeType === Node.TEXT_NODE ? el.parentElement : el);
    const cell = el?.closest('td,th');
    return cell && editor.contains(cell) ? cell : null;
  }

  function placeCaretIn(cell) {
    const range = document.createRange();
    range.selectNodeContents(cell);
    range.collapse(false);
    const sel = getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function insertTable() {
    const bodyRow = '<tr>' + '<td><br></td>'.repeat(3) + '</tr>';
    const html = '<table><thead><tr>' + '<th><br></th>'.repeat(3) + '</tr></thead>'
      + '<tbody>' + bodyRow + bodyRow + '</tbody></table><p><br></p>';
    editor.focus();
    document.execCommand('insertHTML', false, html);
    queueSave();
  }
  $('#insertTableBtn').onclick = insertTable;

  function addTableRow(table, afterRow = null) {
    const cols = table.rows[0] ? table.rows[0].cells.length : 1;
    const tr = document.createElement('tr');
    for (let i = 0; i < cols; i++) {
      const td = document.createElement('td');
      td.innerHTML = '<br>';
      tr.appendChild(td);
    }
    const body = table.tBodies[0] || table;
    if (afterRow && afterRow.parentElement && afterRow.parentElement.tagName !== 'THEAD') {
      afterRow.after(tr);          // below the caret's row
    } else if (afterRow) {
      body.insertBefore(tr, body.firstChild);   // caret in header → first body row
    } else {
      body.appendChild(tr);        // Tab past the last cell → append
    }
    return tr;
  }

  function hideTableMenu() {
    tableMenu.classList.add('hidden');
  }

  function positionTableMenu() {
    const cell = currentCell();
    if (!cell) return hideTableMenu();
    const table = cell.closest('table');

    tableMenu.classList.remove('hidden');
    const host = editorPanel.getBoundingClientRect();
    const rect = table.getBoundingClientRect();
    let top = rect.top - host.top - tableMenu.offsetHeight - 8;
    if (top < 8) top = rect.top - host.top + 8;
    const left = Math.min(
      Math.max(rect.left - host.left, 8),
      host.width - tableMenu.offsetWidth - 8
    );
    tableMenu.style.top = `${top}px`;
    tableMenu.style.left = `${left}px`;
  }

  tableMenu.addEventListener('mousedown', e => e.preventDefault());

  tableMenu.addEventListener('click', e => {
    const btn = e.target.closest('button');
    const cell = currentCell();
    if (!btn || !cell) return;
    const table = cell.closest('table');
    const row = cell.parentElement;
    const idx = cell.cellIndex;

    switch (btn.dataset.tbl) {
      case 'addRow': {
        placeCaretIn(addTableRow(table, row).cells[0]);
        break;
      }
      case 'addCol': {
        [...table.rows].forEach(r => {
          const newCell = document.createElement(r.parentElement.tagName === 'THEAD' ? 'th' : 'td');
          newCell.innerHTML = '<br>';
          const ref = r.cells[Math.min(idx, r.cells.length - 1)];
          ref ? ref.after(newCell) : r.appendChild(newCell);
        });
        break;
      }
      case 'delRow': {
        row.remove();
        if (!table.rows.length) table.remove();
        break;
      }
      case 'delCol': {
        [...table.rows].forEach(r => r.cells[idx]?.remove());
        if (!table.rows[0] || !table.rows[0].cells.length) table.remove();
        break;
      }
      case 'delTable': {
        table.remove();
        break;
      }
    }
    queueSave();
    positionTableMenu();
  });

  // ---------------------------------------------------------------------
  // Markdown typing shortcuts
  // ---------------------------------------------------------------------
  //
  // Block markers applied on Space:  #, ##, ###, -, *, 1., >
  // Block markers applied on Enter:  ``` (code block), --- (divider)
  // Inline markers applied as you type: **bold**, *italic*, ~~strike~~, `code`

  const BLOCK_SHORTCUTS = {
    '#': () => document.execCommand('formatBlock', false, 'h1'),
    '##': () => document.execCommand('formatBlock', false, 'h2'),
    '###': () => document.execCommand('formatBlock', false, 'h3'),
    '####': () => document.execCommand('formatBlock', false, 'h4'),
    '#####': () => document.execCommand('formatBlock', false, 'h5'),
    '######': () => document.execCommand('formatBlock', false, 'h6'),
    '-': () => document.execCommand('insertUnorderedList'),
    '*': () => document.execCommand('insertUnorderedList'),
    '1.': () => document.execCommand('insertOrderedList'),
    '>': () => document.execCommand('formatBlock', false, 'blockquote'),
    '[]': () => toggleChecklist(),
    '[ ]': () => toggleChecklist(),
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

    // A block shortcut is at most six marker characters at the very start.
    // Avoid stringifying an entire long paragraph on every Space key.
    const host = block === editor ? editor : block;
    const walker = document.createTreeWalker(host, NodeFilter.SHOW_TEXT, {
      acceptNode: node => node.textContent.replace(/\u200B/g, '')
        ? NodeFilter.FILTER_ACCEPT
        : NodeFilter.FILTER_SKIP,
    });
    const firstText = walker.nextNode();
    const probe = document.createRange();
    if (range.startContainer === host) {
      // Chrome may place the caret on the contenteditable root while its very
      // first text node is being created.
      if (range.startOffset > 1) return null;
      const leading = range.startOffset ? (host.childNodes[0]?.textContent || '').replace(/\u200B/g, '') : '';
      if (leading.length > 8) return null;
      probe.setStart(host, 0);
      probe.setEnd(host, range.startOffset);
    } else {
      if (!firstText || range.startContainer !== firstText || range.startOffset > 8) return null;
      probe.setStart(firstText, 0);
      probe.setEnd(range.startContainer, range.startOffset);
    }
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

    // Inline markers cannot span a newline. Bound the scan in case the
    // browser represents a very long paragraph as one text node.
    const windowStart = Math.max(0, sel.anchorOffset - 256);
    const upto = node.textContent.slice(windowStart, sel.anchorOffset);
    for (const { tag, re } of INLINE_SHORTCUTS) {
      const m = re.exec(upto);
      if (!m) continue;
      const content = m[m.length - 1];
      const start = windowStart + m.index + (m.length === 3 ? m[1].length : 0);

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

  // ---------------------------------------------------------------------
  // Syntax highlighting for code blocks (highlight.js)
  // ---------------------------------------------------------------------
  //
  // A block is (re)highlighted only while the caret is outside it, so typing
  // is never disturbed; the markup is stripped again on save and rebuilt on
  // load. Language is auto-detected from a common subset.

  const HL_LANGS = ['javascript', 'typescript', 'php', 'python', 'xml', 'css', 'sql', 'bash', 'json', 'java', 'c', 'cpp', 'go', 'rust'];

  function caretInsideNode(el) {
    const sel = getSelection();
    return sel.rangeCount > 0 && el.contains(sel.getRangeAt(0).startContainer);
  }

  // The plain text of a node, with <br> and block children counted as
  // newlines \u2014 matching what the highlighted render will contain.
  function textWithBreaks(node) {
    let out = '';
    node.childNodes.forEach(child => {
      if (child.nodeType === Node.TEXT_NODE) {
        out += child.textContent;
      } else if (child.tagName === 'BR') {
        out += '\n';
      } else {
        out += textWithBreaks(child);
        if (child.tagName === 'DIV' || child.tagName === 'P') out += '\n';
      }
    });
    return out;
  }

  // Caret position inside a pre, measured in characters of its source text.
  function caretOffsetInPre(pre) {
    const sel = getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return null;
    const range = sel.getRangeAt(0);
    if (!pre.contains(range.startContainer)) return null;
    const probe = range.cloneRange();
    probe.selectNodeContents(pre);
    probe.setEnd(range.startContainer, range.startOffset);
    const box = document.createElement('div');
    box.appendChild(probe.cloneContents());
    return textWithBreaks(box).length;
  }

  function setCaretByOffset(el, offset) {
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let remaining = offset;
    let node;
    while ((node = walker.nextNode())) {
      if (remaining <= node.textContent.length) {
        const range = document.createRange();
        range.setStart(node, remaining);
        range.collapse(true);
        const sel = getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        return;
      }
      remaining -= node.textContent.length;
    }
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    const sel = getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  // Re-render one code block; when the caret is inside it, put it back at
  // the same character position afterwards so typing is never disturbed.
  function highlightPre(pre, preserveCaret) {
    if (!window.hljs) return;
    const source = textWithBreaks(pre).replace(/\u200B/g, '').replace(/\n$/, '');
    if (pre.dataset.hl === source) return;
    if (!source.trim()) {
      pre.dataset.hl = source;
      return;
    }
    // highlightAuto tries every configured grammar. Very large pasted blocks
    // can monopolize the UI thread for seconds, so leave those as readable
    // plain text instead of making note navigation janky.
    if (source.length > 10000) {
      pre.dataset.hl = source;
      return;
    }
    const offset = preserveCaret ? caretOffsetInPre(pre) : null;
    pre.innerHTML = hljs.highlightAuto(source, HL_LANGS).value;
    pre.dataset.hl = source;
    if (offset !== null) setCaretByOffset(pre, Math.min(offset, source.length));
  }

  function highlightCode() {
    if (!window.hljs) return;
    $$('pre', editor).forEach(pre => {
      if (!caretInsideNode(pre)) highlightPre(pre, false);
    });
  }

  let highlightGeneration = 0;
  function scheduleHighlightCode() {
    const generation = ++highlightGeneration;
    const blocks = $$('pre', editor);
    let index = 0;
    const next = () => {
      if (generation !== highlightGeneration || index >= blocks.length) return;
      const pre = blocks[index++];
      if (!caretInsideNode(pre)) highlightPre(pre, false);
      setTimeout(next, 0);
    };
    // Paint the selected note first; highlighting is progressive enhancement.
    (window.requestIdleCallback || function (fn) { setTimeout(fn, 16); })(next, { timeout: 250 });
  }

  window.addEventListener('memoir:highlight-ready', scheduleHighlightCode);

  function caretPre() {
    const sel = getSelection();
    if (!sel.rangeCount) return null;
    let el = sel.getRangeAt(0).startContainer;
    el = el.nodeType === Node.TEXT_NODE ? el.parentElement : el;
    const pre = el?.closest('pre');
    return pre && editor.contains(pre) ? pre : null;
  }

  // Rewrite a code block's source around the caret: Tab inserts two spaces,
  // Shift+Tab removes up to two leading spaces from the caret's line.
  function editPreIndent(pre, outdent) {
    const offset = caretOffsetInPre(pre);
    if (offset === null) {
      // A text selection is open — let Tab replace it with an indent.
      if (!outdent) document.execCommand('insertText', false, '  ');
      return;
    }
    const source = textWithBreaks(pre).replace(/\u200B/g, '').replace(/\n$/, '');
    let next, caret;
    if (!outdent) {
      next = source.slice(0, offset) + '  ' + source.slice(offset);
      caret = offset + 2;
    } else {
      const lineStart = source.lastIndexOf('\n', offset - 1) + 1;
      let remove = 0;
      if (source.startsWith('  ', lineStart)) remove = 2;
      else if (source[lineStart] === ' ') remove = 1;
      if (!remove) return;
      next = source.slice(0, lineStart) + source.slice(lineStart + remove);
      caret = Math.max(lineStart, offset - remove);
    }
    pre.textContent = next;
    pre.dataset.hl = '';
    highlightPre(pre, false);
    setCaretByOffset(pre, caret);
    queueSave();
  }

  // Live highlighting: after each keystroke inside a code block, re-render
  // it on the next frame with the caret restored. Skipped mid-IME input and
  // while a selection is open (re-rendering would destroy it).
  let composing = false;
  editor.addEventListener('compositionstart', () => { composing = true; });
  editor.addEventListener('compositionend', () => { composing = false; });

  let hlTimer = null;
  editor.addEventListener('input', () => {
    if (composing || !window.hljs) return;
    const sel = getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return;
    let el = sel.getRangeAt(0).startContainer;
    el = el.nodeType === Node.TEXT_NODE ? el.parentElement : el;
    const pre = el?.closest('pre');
    if (!pre || !editor.contains(pre)) return;
    clearTimeout(hlTimer);
    hlTimer = setTimeout(() => highlightPre(pre, true), 90);
  });

  editor.addEventListener('keydown', e => {
    // While the [[ suggestion menu is open it owns the navigation keys.
    if (!wikiMenu.classList.contains('hidden')) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (wikiMatches.length) {
          wikiIndex = (wikiIndex + (e.key === 'ArrowDown' ? 1 : -1) + wikiMatches.length) % wikiMatches.length;
          $$('#wikiMenu button').forEach((btn, i) => btn.classList.toggle('checked', i === wikiIndex));
        }
        return;
      }
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        const pick = wikiMatches[wikiIndex];
        pick ? insertWikiLink(pick) : closeWikiMenu();
        return;
      }
      if (e.key === 'Escape') {
        closeWikiMenu();
        return;
      }
    }

    if (e.key === ' ') handleBlockShortcut(e);
    if (e.key === 'Enter') handleEnterShortcut(e);

    // Enter on an empty last line of a code block exits the block.
    if (e.key === 'Enter' && !e.shiftKey && !e.defaultPrevented) {
      const pre = caretPre();
      if (pre) {
        const raw = textWithBreaks(pre).replace(/\u200B/g, '');
        const offset = caretOffsetInPre(pre);
        if (offset === raw.length && raw.endsWith('\n')) {
          e.preventDefault();
          pre.textContent = raw.replace(/\s+$/, '');
          pre.dataset.hl = '';
          highlightPre(pre, false);
          const p = document.createElement('p');
          p.innerHTML = '<br>';
          pre.after(p);
          const range = document.createRange();
          range.setStart(p, 0);
          range.collapse(true);
          const sel = getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
          queueSave();
          return;
        }
      }
    }

    // Enter in a checked item: the browser clones the <li> with its
    // attributes, so reset the new item to unchecked.
    if (e.key === 'Enter' && !e.shiftKey) {
      const li = closestChecklistLi(getSelection().anchorNode);
      if (li) {
        setTimeout(() => {
          const fresh = closestChecklistLi(getSelection().anchorNode);
          if (fresh && fresh !== li) fresh.dataset.checked = '0';
        }, 0);
      }
    }

    // Tab: move through table cells (growing the table past the last cell),
    // or indent/outdent inside a code block.
    if (e.key === 'Tab') {
      const cell = currentCell();
      if (cell) {
        e.preventDefault();
        const table = cell.closest('table');
        const cells = [...table.querySelectorAll('th,td')];
        const i = cells.indexOf(cell);
        if (e.shiftKey) {
          if (i > 0) placeCaretIn(cells[i - 1]);
        } else if (i < cells.length - 1) {
          placeCaretIn(cells[i + 1]);
        } else {
          placeCaretIn(addTableRow(table).cells[0]);
          queueSave();
        }
        return;
      }
      const pre = caretPre();
      if (pre) {
        e.preventDefault();
        editPreIndent(pre, e.shiftKey);
      }
    }
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

  let wordCountTimer = null;
  let wordCountGeneration = 0;
  function updateWords(immediate = true) {
    clearTimeout(wordCountTimer);
    const generation = ++wordCountGeneration;
    const count = () => {
      if (generation !== wordCountGeneration) return;
      const text = $('#noteContent').innerText.trim();
      const words = text ? (text.match(/\S+/g) || []).length : 0;
      $('#wordCount').textContent = `${words} words`;
    };
    if (immediate) return count();

    // The count is informational. Run it only after typing pauses and in an
    // idle slice so it never competes with contenteditable input or paint.
    wordCountTimer = setTimeout(() => {
      (window.requestIdleCallback || function (callback) { setTimeout(callback, 0); })(count, { timeout: 700 });
    }, 240);
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

  // ---------------------------------------------------------------------
  // Note version history
  // ---------------------------------------------------------------------

  let selectedVersionId = null;

  function formatVersionDate(value) {
    try {
      return new Date(value.replace(' ', 'T')).toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
      });
    } catch {
      return value || '';
    }
  }

  async function previewVersion(versionId) {
    if (!current) return;
    const d = await api('note-version', { query: `&note_id=${current.id}&version_id=${versionId}` });
    selectedVersionId = String(d.version.id);
    $$('#historyList button').forEach(button => button.classList.toggle('active', button.dataset.versionId === selectedVersionId));
    $('#historyPreviewMeta').textContent = `${formatVersionDate(d.version.created_at)} · ${d.version.source === 'restore' ? 'Before restore' : 'Automatic snapshot'}`;
    $('#historyPreviewTitle').textContent = d.version.title || 'Untitled note';
    $('#historyPreviewContent').innerHTML = d.version.content || '<p><em>Empty note</em></p>';
    $('#restoreVersion').disabled = false;
  }

  async function openHistory() {
    if (!current || current.deleted_at) return;
    if (saveTimer !== null) await saveNote();
    else if (savePromise) await savePromise;
    selectedVersionId = null;
    $('#restoreVersion').disabled = true;
    $('#historyStatus').textContent = '';
    $('#historyPreviewMeta').textContent = 'Select a version to preview';
    $('#historyPreviewTitle').textContent = '';
    $('#historyPreviewContent').innerHTML = '';
    $('#historyList').innerHTML = '<span class="history-empty">Loading history…</span>';
    openModal('#historyModal');
    try {
      const d = await api('note-history', { query: `&id=${current.id}` });
      if (!d.versions.length) {
        $('#historyList').innerHTML = '<span class="history-empty">No earlier versions yet. A snapshot is created when you begin changing this note.</span>';
        return;
      }
      $('#historyList').innerHTML = d.versions.map(version => `
        <button type="button" data-version-id="${version.id}">
          <strong>${escapeHtml(version.title || 'Untitled note')}</strong>
          <span>${escapeHtml(formatVersionDate(version.created_at))}${version.source === 'restore' ? ' · before restore' : ''}</span>
        </button>`).join('');
      previewVersion(d.versions[0].id);
    } catch (err) {
      $('#historyList').innerHTML = `<span class="history-empty">${escapeHtml(err.message)}</span>`;
    }
  }

  $('#historyNote').onclick = openHistory;

  async function openActivity() {
    if (!current) return;
    const list = $('#activityList');
    list.innerHTML = '<span class="history-empty">Loading activity…</span>';
    openModal('#activityModal');
    try {
      const d = await api('note-activity', { query: `&note_id=${current.id}` });
      if (!d.activity.length) {
        list.innerHTML = '<span class="history-empty">No activity yet on this note.</span>';
        return;
      }
      list.innerHTML = d.activity.map(entry => `
        <div class="activity-row">
          <strong>${escapeHtml(entry.actor_name || 'Someone')}</strong>
          <span>${escapeHtml(entry.message)}</span>
          <span class="activity-date">${escapeHtml(formatVersionDate(entry.created_at))}</span>
        </div>`).join('');
    } catch (err) {
      list.innerHTML = `<span class="history-empty">${escapeHtml(err.message)}</span>`;
    }
  }

  $('#activityNote').onclick = openActivity;
  $('#historyList').onclick = e => {
    const button = e.target.closest('button[data-version-id]');
    if (button) previewVersion(button.dataset.versionId);
  };
  $('#restoreVersion').onclick = async () => {
    if (!current || !selectedVersionId || !confirm('Restore this version? The current note will be preserved in history.')) return;
    const status = $('#historyStatus');
    status.textContent = 'Restoring…';
    try {
      await api('restore-version', {
        method: 'POST',
        body: JSON.stringify({ note_id: current.id, version_id: selectedVersionId }),
      });
      const noteId = current.id;
      closeModal($('#historyModal'));
      await loadNote(noteId, false);
      await refreshList();
    } catch (err) {
      status.textContent = err.message;
    }
  };

  // New folder modal
  let folderIcon = 'fa-folder';
  let folderColor = '#6F5EE8';
  let editingFolderId = null;   // set while the folder modal edits, not creates

  function openFolderModal(row = null) {
    editingFolderId = row ? row.querySelector('.folder-item').dataset.folder : null;
    $('#folderModalTitle').textContent = editingFolderId ? 'Edit folder' : 'New folder';
    $('#saveFolder').textContent = editingFolderId ? 'Save folder' : 'Create folder';
    $$('#folderIcons button, #folderColors button').forEach(b => b.classList.remove('selected'));
    if (row) {
      const item = row.querySelector('.folder-item');
      $('#folderName').value = item.querySelector('span').textContent;
      const icon = [...item.querySelector('i').classList].find(c => c.startsWith('fa-') && c !== 'fa-solid');
      folderIcon = icon || 'fa-folder';
      folderColor = item.querySelector('i').style.color || '#6F5EE8';
      $(`#folderIcons button[data-icon="${CSS.escape(folderIcon)}"]`)?.classList.add('selected');
    } else {
      $('#folderName').value = '';
      folderIcon = 'fa-folder';
      folderColor = '#6F5EE8';
    }
    openModal('#folderModal');
  }

  $('#addFolder').onclick = () => openFolderModal();

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

  function folderRowHtml(d, count = 0) {
    return `<div class="folder-row">
      <button class="folder-item" data-folder="${d.id}"><i class="fa-solid ${d.icon}" style="color:${d.color}"></i><span>${escapeHtml(d.name)}</span><span class="count">${count}</span></button>
      <button class="folder-menu-btn" data-folder="${d.id}" type="button" aria-label="Folder options"><i class="fa-solid fa-ellipsis"></i></button>
    </div>`;
  }

  $('#saveFolder').onclick = async () => {
    const name = $('#folderName').value.trim();
    if (!name) return;
    const payload = { name, icon: folderIcon, color: folderColor };
    if (editingFolderId) {
      const d = await api('rename-folder', {
        method: 'POST',
        body: JSON.stringify({ id: editingFolderId, ...payload }),
      });
      const item = $(`.folder-item[data-folder="${CSS.escape(String(d.id))}"]`);
      if (item) {
        item.querySelector('span').textContent = d.name;
        item.querySelector('i').className = `fa-solid ${d.icon}`;
        item.querySelector('i').style.color = d.color;
      }
      if (current && String(current.folder_id) === String(d.id)) {
        current.folder_name = d.name;
        $('#crumbFolder').textContent = d.name;
      }
    } else {
      const d = await api('folder', { method: 'POST', body: JSON.stringify(payload) });
      $('#folderList').insertAdjacentHTML('beforeend', folderRowHtml(d));
    }
    $('#folderName').value = '';
    closeModal($('#folderModal'));
  };

  // Folder ... menu: edit, reorder, delete.
  const folderMenu = $('#folderMenu');
  let folderMenuRow = null;

  function closeMiniMenus() {
    folderMenu.classList.add('hidden');
    folderPicker.classList.add('hidden');
    $('#sortMenu').classList.add('hidden');
    $('#wikiMenu').classList.add('hidden');
    $('#shareMenu').classList.add('hidden');
  }

  function openMiniMenu(menu, anchor) {
    menu.classList.remove('hidden');
    const rect = anchor.getBoundingClientRect();
    const top = Math.min(rect.bottom + 6, innerHeight - menu.offsetHeight - 10);
    const left = Math.min(Math.max(rect.left, 10), innerWidth - menu.offsetWidth - 10);
    menu.style.top = `${top}px`;
    menu.style.left = `${left}px`;
  }

  $('#folderList').addEventListener('click', e => {
    const btn = e.target.closest('.folder-menu-btn');
    if (!btn) return;
    e.stopPropagation();
    folderMenuRow = btn.closest('.folder-row');
    openMiniMenu(folderMenu, btn);
  });

  folderMenu.addEventListener('click', async e => {
    const btn = e.target.closest('button[data-fm]');
    if (!btn || !folderMenuRow) return;
    const row = folderMenuRow;
    const id = row.querySelector('.folder-item').dataset.folder;
    const name = row.querySelector('.folder-item span').textContent;
    closeMiniMenus();

    switch (btn.dataset.fm) {
      case 'edit':
        openFolderModal(row);
        break;
      case 'up':
      case 'down': {
        const sibling = btn.dataset.fm === 'up' ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling) break;
        btn.dataset.fm === 'up' ? sibling.before(row) : sibling.after(row);
        const ids = $$('#folderList .folder-item').map(f => f.dataset.folder);
        await api('reorder-folders', { method: 'POST', body: JSON.stringify({ ids }) });
        break;
      }
      case 'delete': {
        if (!confirm(`Delete the folder "${name}"? Its notes move to Unfiled.`)) break;
        await api('delete-folder', { method: 'POST', body: JSON.stringify({ id }) });
        row.remove();
        if (filterFolder === id) {
          filterFolder = '';
          clearFilterHighlights();
          $('.nav-item[data-folder=""]')?.classList.add('active');
          $('#listTitle').textContent = 'All notes';
        }
        if (current && String(current.folder_id) === String(id)) {
          current.folder_id = null;
          current.folder_name = null;
          $('#crumbFolder').textContent = 'Unfiled';
        }
        await refreshList();
        break;
      }
    }
  });

  // Breadcrumb folder picker: move the open note to another folder.
  const folderPicker = $('#folderPicker');

  $('#crumbFolder').onclick = e => {
    if (!current || current.deleted_at) return;
    const folders = $$('#folderList .folder-item').map(f => ({
      id: f.dataset.folder,
      name: f.querySelector('span').textContent,
    }));
    const items = [{ id: '', name: 'Unfiled' }, ...folders];
    folderPicker.innerHTML = items.map(f =>
      `<button type="button" data-pick="${f.id}" class="${String(current.folder_id ?? '') === f.id ? 'checked' : ''}">
        <i class="fa-solid ${f.id === '' ? 'fa-inbox' : 'fa-folder'}"></i> ${escapeHtml(f.name)}
      </button>`
    ).join('');
    openMiniMenu(folderPicker, e.currentTarget);
  };

  folderPicker.addEventListener('click', e => {
    const btn = e.target.closest('button[data-pick]');
    if (!btn || !current) return;
    const id = btn.dataset.pick;
    current.folder_id = id === '' ? null : id;
    current.folder_name = id === '' ? null : btn.textContent.trim();
    $('#crumbFolder').textContent = current.folder_name || 'Unfiled';
    closeMiniMenus();
    queueSave();
  });

  document.addEventListener('pointerdown', e => {
    if (e.target.closest('#folderMenu, #folderPicker, .folder-menu-btn, #crumbFolder, #sortMenu, #sortBtn, #shareMenu, #shareNote')) return;
    closeMiniMenus();
  });

  // ---------------------------------------------------------------------
  // Sort options
  // ---------------------------------------------------------------------

  const sortMenu = $('#sortMenu');

  function syncSortMenu() {
    $$('#sortMenu button').forEach(btn =>
      btn.classList.toggle('checked', btn.dataset.sort === sortMode));
  }

  $('#sortBtn').onclick = e => {
    if (!sortMenu.classList.contains('hidden')) {
      sortMenu.classList.add('hidden');
      return;
    }
    syncSortMenu();
    openMiniMenu(sortMenu, e.currentTarget);
  };

  sortMenu.addEventListener('click', e => {
    const btn = e.target.closest('button[data-sort]');
    if (!btn) return;
    sortMode = btn.dataset.sort;
    try {
      localStorage.setItem('memoir-sort', sortMode);
    } catch {}
    sortMenu.classList.add('hidden');
    refreshList();
  });

  // ---------------------------------------------------------------------
  // Quick switcher (Ctrl+P)
  // ---------------------------------------------------------------------

  let switcherCache = (window.MEMOIR.initialNotes || []).map(note => ({
    id: note.id,
    title: note.title,
    folder_name: note.folder_name,
    updated_at: note.updated_at,
  }));
  let switcherComplete = !!window.MEMOIR.initialActiveComplete;
  let paletteMatches = [];
  let paletteIndex = 0;

  async function ensureSwitcherCache() {
    if (switcherComplete) return;
    try {
      switcherCache = (await api('switcher')).notes;
      switcherComplete = true;
    } catch {
      // Keep the initial page cache available while offline.
    }
  }

  function renderPalette(q) {
    const ql = q.trim().toLowerCase();
    const words = ql.split(/\s+/).filter(Boolean);
    paletteMatches = switcherCache
      .map(n => {
        const t = (n.title || 'untitled note').toLowerCase();
        let score = -1;
        if (!words.length) score = 0;
        else if (t.startsWith(ql)) score = 3;
        else if (t.includes(ql)) score = 2;
        else if (words.every(w => t.includes(w))) score = 1;
        return { n, score };
      })
      .filter(x => x.score >= 0)
      .sort((a, b) => b.score - a.score)
      .slice(0, 12)
      .map(x => x.n);
    paletteIndex = 0;
    $('#paletteResults').innerHTML = paletteMatches.map((n, i) =>
      `<button type="button" data-id="${n.id}" class="${i === 0 ? 'active' : ''}">
        <span class="pal-title">${markMatches(n.title || 'Untitled note', q)}</span>
        <span class="pal-meta">${escapeHtml(n.folder_name || 'Unfiled')}</span>
      </button>`
    ).join('') || '<div class="pal-empty">No matching notes</div>';
  }

  function movePaletteIndex(delta) {
    if (!paletteMatches.length) return;
    paletteIndex = (paletteIndex + delta + paletteMatches.length) % paletteMatches.length;
    $$('#paletteResults button').forEach((btn, i) => {
      btn.classList.toggle('active', i === paletteIndex);
      if (i === paletteIndex) btn.scrollIntoView({ block: 'nearest' });
    });
  }

  async function openPalette() {
    $('#palette').classList.remove('hidden');
    $('#paletteInput').value = '';
    await ensureSwitcherCache();
    renderPalette('');
    $('#paletteInput').focus();
  }

  function closePalette() {
    $('#palette').classList.add('hidden');
  }

  $('#paletteInput').addEventListener('input', e => renderPalette(e.target.value));
  $('#paletteInput').addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); movePaletteIndex(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); movePaletteIndex(-1); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      const pick = paletteMatches[paletteIndex];
      if (pick) { closePalette(); loadNote(pick.id); }
    } else if (e.key === 'Escape') {
      closePalette();
    }
  });
  $('#paletteResults').addEventListener('click', e => {
    const btn = e.target.closest('button[data-id]');
    if (!btn) return;
    closePalette();
    loadNote(btn.dataset.id);
  });
  $('#palette').addEventListener('pointerdown', e => {
    if (e.target === e.currentTarget) closePalette();
  });

  // ---------------------------------------------------------------------
  // Wiki links: [[ autocompletion, click-to-follow, backlinks
  // ---------------------------------------------------------------------

  const wikiMenu = $('#wikiMenu');
  let wikiMatches = [];
  let wikiIndex = 0;
  let wikiCtx = null;

  function wikiContext() {
    const sel = getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return null;
    const node = sel.anchorNode;
    if (node.nodeType !== Node.TEXT_NODE || !editor.contains(node)) return null;
    if (node.parentElement.closest('pre, code, a')) return null;
    const windowStart = Math.max(0, sel.anchorOffset - 256);
    const upto = node.textContent.slice(windowStart, sel.anchorOffset);
    const m = /\[\[([^\[\]\n]*)$/.exec(upto);
    if (!m) return null;
    return { node, start: windowStart + m.index, offset: sel.anchorOffset, query: m[1] };
  }

  function closedWikiContext() {
    const sel = getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return null;
    const node = sel.anchorNode;
    if (node.nodeType !== Node.TEXT_NODE || !editor.contains(node)) return null;
    if (node.parentElement.closest('pre, code, a')) return null;
    // Wiki markers are short and never cross a newline.
    const windowStart = Math.max(0, sel.anchorOffset - 256);
    const upto = node.textContent.slice(windowStart, sel.anchorOffset);
    const match = /\[\[([^\[\]\n]+)\]\]$/.exec(upto);
    if (!match) return null;
    return { node, start: windowStart + match.index, offset: sel.anchorOffset, query: match[1] };
  }

  function closeWikiMenu() {
    wikiMenu.classList.add('hidden');
    wikiCtx = null;
  }

  function renderWikiMenu() {
    const ql = wikiCtx.query.trim().toLowerCase();
    wikiMatches = switcherCache
      .filter(n => String(n.id) !== String(current?.id))
      .map(n => {
        const title = (n.title || 'Untitled note').toLowerCase();
        const folder = (n.folder_name || 'Unfiled').toLowerCase();
        const words = ql.split(/\s+/).filter(Boolean);
        let score = 0;
        if (!ql) score = 1;
        else if (title === ql) score = 100;
        else if (title.startsWith(ql)) score = 80;
        else if (title.includes(ql)) score = 60;
        else if (words.every(word => title.includes(word))) score = 40;
        else if (folder.includes(ql)) score = 20;
        return { note: n, score };
      })
      .filter(result => result.score > 0)
      .sort((a, b) => b.score - a.score)
      .slice(0, 8);
    wikiMatches = wikiMatches.map(result => result.note);
    wikiIndex = 0;
    wikiMenu.innerHTML = wikiMatches.map((n, i) =>
      `<button type="button" data-id="${n.id}" class="${i === 0 ? 'checked' : ''}">
        <i class="fa-regular fa-note-sticky"></i>
        <span class="wiki-result-copy">
          <strong>${markMatches(n.title || 'Untitled note', wikiCtx.query.trim())}</strong>
          <small>${escapeHtml(n.folder_name || 'Unfiled')}</small>
        </span>
      </button>`
    ).join('') || '<div class="wiki-empty">No matching notes</div>';
  }

  async function updateWikiMenu() {
    let closed = closedWikiContext();
    if (closed) {
      await ensureSwitcherCache();
      closed = closedWikiContext();
      if (!closed) return;
      const title = closed.query.trim().toLocaleLowerCase();
      const exact = switcherCache.find(note =>
        String(note.id) !== String(current?.id)
        && (note.title || 'Untitled note').trim().toLocaleLowerCase() === title
      );
      if (exact) insertWikiLink(exact, closed);
      else closeWikiMenu();
      return;
    }

    wikiCtx = wikiContext();
    if (!wikiCtx) return closeWikiMenu();
    await ensureSwitcherCache();
    // The caret may have moved while fetching.
    wikiCtx = wikiContext();
    if (!wikiCtx) return closeWikiMenu();
    renderWikiMenu();
    const sel = getSelection();
    const rect = sel.getRangeAt(0).getBoundingClientRect();
    wikiMenu.classList.remove('hidden');
    const top = Math.min(rect.bottom + 6, innerHeight - wikiMenu.offsetHeight - 10);
    const left = Math.min(Math.max(rect.left, 10), innerWidth - wikiMenu.offsetWidth - 10);
    wikiMenu.style.top = `${top}px`;
    wikiMenu.style.left = `${left}px`;
  }

  function insertWikiLink(noteRef, context = wikiCtx) {
    if (!context) return;
    const range = document.createRange();
    range.setStart(context.node, context.start);
    range.setEnd(context.node, context.offset);
    range.deleteContents();
    const link = document.createElement('a');
    link.dataset.noteLink = noteRef.id;
    link.textContent = noteRef.title || 'Untitled note';
    range.insertNode(link);
    const tail = document.createTextNode(' ');
    link.after(tail);
    const caret = document.createRange();
    caret.setStart(tail, 1);
    caret.collapse(true);
    const sel = getSelection();
    sel.removeAllRanges();
    sel.addRange(caret);
    closeWikiMenu();
    queueSave();
  }

  wikiMenu.addEventListener('mousedown', e => e.preventDefault());
  wikiMenu.addEventListener('click', e => {
    const btn = e.target.closest('button[data-id]');
    if (!btn) return;
    insertWikiLink(switcherCache.find(n => String(n.id) === btn.dataset.id) || { id: btn.dataset.id, title: btn.textContent.trim() });
  });

  // Clicking a wiki link follows it.
  editor.addEventListener('click', e => {
    const link = e.target.closest('a[data-note-link]');
    if (!link) return;
    e.preventDefault();
    loadNote(link.dataset.noteLink);
  });

  $('#backlinks').addEventListener('click', e => {
    const btn = e.target.closest('button[data-id]');
    if (btn) loadNote(btn.dataset.id);
  });

  // ---------------------------------------------------------------------
  // Public share links
  // ---------------------------------------------------------------------

  const shareMenu = $('#shareMenu');
  const isOwner = window.MEMOIR.userRole === 'owner';
  if (!isOwner) {
    // Trash/restore/permanent-delete and public link sharing are the note
    // owner's call — a collaborator only edits content.
    $('#shareNote').classList.add('hidden');
    $('#deleteNote').classList.add('hidden');
    $('#restoreNote').classList.add('hidden');
    $('#destroyNote').classList.add('hidden');
  }

  function shareUrlFor(token) {
    return new URL(`share.php?t=${token}`, location.href).href;
  }

  function renderShareMenu() {
    const shared = !!current?.share_token;
    $('#shareOff').classList.toggle('hidden', shared);
    $('#shareOn').classList.toggle('hidden', !shared);
    if (shared) $('#shareUrl').value = shareUrlFor(current.share_token);
    $('#shareNote').classList.toggle('active', shared);
  }

  function collaboratorRowHtml(c) {
    const label = c.status === 'pending' ? `${escapeHtml(c.invited_email)} <em>(invited)</em>` : escapeHtml(c.user_name || c.invited_email);
    const resend = c.status === 'pending' ? `<button type="button" data-resend="${c.id}" title="Resend invite"><i class="fa-solid fa-rotate"></i></button>` : '';
    return `<div class="collaborator-row" data-collaborator="${c.id}">
      <span>${label}</span>
      ${resend}
      <button type="button" class="danger" data-remove="${c.id}" title="Remove access"><i class="fa-regular fa-trash-can"></i></button>
    </div>`;
  }

  async function loadCollaborators() {
    const list = $('#collaboratorList');
    if (!current) return;
    list.innerHTML = '<span class="history-empty">Loading…</span>';
    try {
      const d = await api('list-collaborators', { query: `&note_id=${current.id}` });
      list.innerHTML = d.collaborators.length
        ? d.collaborators.map(collaboratorRowHtml).join('')
        : '<span class="history-empty">Only you have access.</span>';
    } catch (err) {
      list.innerHTML = `<span class="history-empty">${escapeHtml(err.message)}</span>`;
    }
  }

  $('#shareNote').onclick = e => {
    if (!current || current.deleted_at) return;
    if (!shareMenu.classList.contains('hidden')) {
      shareMenu.classList.add('hidden');
      return;
    }
    renderShareMenu();
    $('#inviteStatus').textContent = '';
    $('#inviteEmail').value = '';
    loadCollaborators();
    openMiniMenu(shareMenu, e.currentTarget);
  };

  $('#inviteForm').addEventListener('submit', async e => {
    e.preventDefault();
    if (!current) return;
    const email = $('#inviteEmail').value.trim();
    const status = $('#inviteStatus');
    status.className = 'pw-status';
    status.textContent = 'Sending invite…';
    try {
      await api('invite-collaborator', { method: 'POST', body: JSON.stringify({ note_id: current.id, email }) });
      status.className = 'pw-status ok';
      status.textContent = 'Invite sent.';
      $('#inviteEmail').value = '';
      loadCollaborators();
    } catch (err) {
      status.className = 'pw-status error';
      status.textContent = err.message;
    }
  });

  $('#collaboratorList').addEventListener('click', async e => {
    const removeBtn = e.target.closest('button[data-remove]');
    const resendBtn = e.target.closest('button[data-resend]');
    if (!current || (!removeBtn && !resendBtn)) return;
    const status = $('#inviteStatus');
    try {
      if (removeBtn) {
        if (!confirm('Remove this person\'s access to the note?')) return;
        await api('remove-collaborator', { method: 'POST', body: JSON.stringify({ note_id: current.id, id: removeBtn.dataset.remove }) });
      } else {
        await api('resend-invite', { method: 'POST', body: JSON.stringify({ note_id: current.id, id: resendBtn.dataset.resend }) });
        status.className = 'pw-status ok';
        status.textContent = 'Invite resent.';
      }
      loadCollaborators();
    } catch (err) {
      status.className = 'pw-status error';
      status.textContent = err.message;
    }
  });

  $('#shareEnable').onclick = async () => {
    if (!current) return;
    const d = await api('share-note', { method: 'POST', body: JSON.stringify({ id: current.id, enable: true }) });
    current.share_token = d.token;
    renderShareMenu();
  };

  $('#shareDisable').onclick = async () => {
    if (!current) return;
    await api('share-note', { method: 'POST', body: JSON.stringify({ id: current.id, enable: false }) });
    current.share_token = null;
    renderShareMenu();
    shareMenu.classList.add('hidden');
  };

  $('#copyShare').onclick = async () => {
    const input = $('#shareUrl');
    try {
      await navigator.clipboard.writeText(input.value);
    } catch {
      input.select();
      document.execCommand('copy');
    }
    const btn = $('#copyShare');
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
    setTimeout(() => {
      btn.innerHTML = '<i class="fa-regular fa-copy"></i> Copy link';
    }, 1400);
  };

  // ---------------------------------------------------------------------
  // Import (Settings → Data)
  // ---------------------------------------------------------------------

  $('#importBtn').onclick = () => {
    $('#importFiles').click();
  };

  $('#importFiles').addEventListener('change', async e => {
    const input = e.target;

    const files = [...input.files];

    if (!files.length) {
      return;
    }

    const status = $('#importStatus');

    status.className = 'pw-status';

    status.textContent =
      `Importing ${files.length} file${files.length > 1 ? 's' : ''}…`;

    const fd = new FormData();

    files.forEach(file => {
      fd.append('files[]', file);
    });

    try {

      const d = await api('import', {
        method: 'POST',
        body: fd
      });

      status.className = 'pw-status ok';

      status.textContent =
        `Imported ${d.imported} note${d.imported === 1 ? '' : 's'}`
        + (d.skipped ? ` (${d.skipped} skipped)` : '')
        + '.';

      // Refresh in background.
      refreshList().catch(() => {});
      refreshSidebar();

    } catch (err) {

      status.className = 'pw-status error';

      status.textContent =
        err.message || 'Import failed.';

    } finally {

      // Important:
      // allow the same file to be selected again.
      input.value = '';
    }
  });

  $('#downloadBackup').onclick = () => {
    const link = document.createElement('a');
    link.href = 'api.php?action=backup-export';
    link.download = '';
    document.body.appendChild(link);
    link.click();
    link.remove();
  };

  $('#backupNow').onclick = async () => {
    const status = $('#backupStatus');
    status.className = 'pw-status';
    status.textContent = 'Creating server backup…';
    try {
      const d = await api('backup-now', { method: 'POST', body: '{}' });
      status.className = 'pw-status ok';
      status.textContent = `Saved securely as ${d.filename}.`;
    } catch (err) {
      status.className = 'pw-status error';
      status.textContent = err.message;
    }
  };

  $('#restoreBackup').onclick = () => $('#restoreBackupFile').click();
  $('#restoreBackupFile').addEventListener('change', async e => {
    const file = e.target.files[0];
    if (!file) return;
    const status = $('#restoreBackupStatus');
    if (!confirm(`Restore “${file.name}”? This replaces the current workspace after creating a safety backup.`)) {
      e.target.value = '';
      return;
    }
    status.className = 'pw-status';
    status.textContent = 'Validating and restoring backup…';
    const form = new FormData();
    form.append('backup', file);
    try {
      const d = await api('backup-restore', { method: 'POST', body: form });
      status.className = 'pw-status ok';
      status.textContent = `Restored ${d.notes} notes, ${d.folders} folders, and ${d.versions} versions. Reloading…`;
      setTimeout(() => location.reload(), 900);
    } catch (err) {
      status.className = 'pw-status error';
      status.textContent = err.message;
    }
    e.target.value = '';
  });

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

  let updateState = null;

  function formatUpdateTime(value) {
    if (!value) return 'Never';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Unknown' : date.toLocaleString();
  }

  function renderUpdateState(state) {
    updateState = state;
    const available = Boolean(state.update_available);
    $('#updateLatest').textContent = state.latest_version ? `v${state.latest_version}` : 'Unavailable';
    $('#updateChecked').textContent = formatUpdateTime(state.last_checked);
    $('#updateNavBadge').classList.toggle('hidden', !available);
    $('#installUpdate').classList.toggle('hidden', !available || !state.can_install);
    $('#installUpdate').dataset.version = available ? state.latest_version : '';

    if (state.error) {
      $('#updateTitle').textContent = 'Could not check for updates';
      $('#updateSummary').textContent = state.error;
      $('#updateIcon').className = 'update-icon warning';
    } else if (available) {
      $('#updateTitle').textContent = `Memoir ${state.latest_version} is ready`;
      $('#updateSummary').textContent = state.release_name || 'A newer release is available.';
      $('#updateIcon').className = 'update-icon available';
    } else {
      $('#updateTitle').textContent = 'Memoir is up to date';
      $('#updateSummary').textContent = `You are running the latest version (${state.current_version}).`;
      $('#updateIcon').className = 'update-icon current';
    }

    const issues = state.install_issues || [];
    $('#updateCapability').textContent = issues.length
      ? `${issues.join(' ')} You can still download and install the release manually.`
      : 'Memoir checks GitHub once per day. Updates are downloaded only when you choose Update now.';
    $('#updateNotes').textContent = state.release_notes || '';
    $('#updateNotes').classList.toggle('hidden', !state.release_notes || !available);
    const releaseLink = $('#viewRelease');
    releaseLink.classList.toggle('hidden', !state.release_url);
    if (state.release_url) releaseLink.href = state.release_url;
  }

  async function loadUpdateState(manual = false) {
    const button = $('#checkUpdate');
    const status = $('#updateStatus');
    if (manual) {
      button.disabled = true;
      status.className = 'pw-status';
      status.textContent = 'Checking GitHub…';
    }
    try {
      const state = await api(manual ? 'check-update' : 'update-status', manual ? { method: 'POST' } : {});
      renderUpdateState(state);
      if (manual) {
        status.className = state.error ? 'pw-status error' : 'pw-status success';
        status.textContent = state.error
          ? state.error
          : state.cooldown
            ? `Already checked recently. Try again in ${state.cooldown}s.`
            : 'Update check complete.';
      }
    } catch (error) {
      if (manual) {
        status.className = 'pw-status error';
        status.textContent = error.message;
      }
    } finally {
      if (manual) button.disabled = false;
    }
  }

  $('#settingsBtn').onclick = () => openModal('#settingsModal');
  $('#whatsNewBtn').onclick = () => openModal('#whatsNewModal');
  $('#collapseSidebar').onclick = () => document.body.classList.add('sidebar-open');
  $('#closeSidebar').onclick = closeSidebar;
  $('#mobileScrim').onclick = closeSidebar;
  $('#backToList').onclick = () => document.body.classList.remove('editor-open');

  $('#checkUpdate').onclick = () => loadUpdateState(true);
  $('#installUpdate').onclick = async () => {
    const version = $('#installUpdate').dataset.version;
    if (!version || !updateState?.update_available) return;
    if (!confirm(`Update Memoir from ${updateState.current_version} to ${version}?\n\nMemoir will create a workspace backup and roll back code files if installation fails.`)) return;

    const button = $('#installUpdate');
    const status = $('#updateStatus');
    button.disabled = true;
    $('#checkUpdate').disabled = true;
    status.className = 'pw-status';
    status.textContent = 'Downloading, verifying, and installing the update… Keep this tab open.';
    try {
      await api('install-update', { method: 'POST', body: JSON.stringify({ version }) });
      status.className = 'pw-status success';
      status.textContent = `Memoir ${version} installed. Reloading…`;
      setTimeout(() => location.reload(), 900);
    } catch (error) {
      status.className = 'pw-status error';
      status.textContent = error.message;
      button.disabled = false;
      $('#checkUpdate').disabled = false;
    }
  };

  // A cached endpoint makes this cheap on every app visit; it contacts GitHub
  // only when the last attempt is at least one day old.
  setTimeout(() => loadUpdateState(false), 1200);

  $('#saveSettings').onclick = async () => {
    const body = {
      app_name: $('#setAppName').value,
      smtp_host: $('#setSmtpHost').value,
      smtp_port: $('#setSmtpPort').value,
      smtp_security: $('#setSmtpSecurity').value,
      smtp_user: $('#setSmtpUser').value,
      smtp_pass: $('#setSmtpPass').value,
      smtp_from: $('#setSmtpFrom').value,
      backup_enabled: $('#backupEnabled').checked,
      backup_interval_hours: $('#backupInterval').value,
      backup_keep: $('#backupKeep').value,
    };
    await api('settings', { method: 'POST', body: JSON.stringify(body) });
    closeModal($('#settingsModal'));
    location.reload();
  };

  // ---------------------------------------------------------------------
  // Theme (light / dark / system, remembered per browser)
  // ---------------------------------------------------------------------

  const THEME_KEY = 'memoir-theme';

  function storedTheme() {
    try {
      return localStorage.getItem(THEME_KEY) || 'system';
    } catch {
      return 'system';
    }
  }

  // Each flavor rides on a light or dark base mode.
  const THEME_MODES = { light: 'light', dark: 'dark', sepia: 'light', ocean: 'dark', midnight: 'dark', forest: 'dark', dusk: 'dark', aurora: 'dark', paper: 'light', nord: 'dark', soft: 'light' };

  function applyTheme(choice) {
    let flavor = choice === 'system'
      ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      : choice;
    if (!THEME_MODES[flavor]) flavor = 'light';
    document.documentElement.dataset.theme = flavor;
    document.documentElement.dataset.mode = THEME_MODES[flavor];
    $$('#themeToggle button').forEach(btn =>
      btn.classList.toggle('active', btn.dataset.themeOpt === choice));

    // Swap the code-highlight palette with the theme mode.
    const hlLight = $('#hlThemeLight');
    const hlDark = $('#hlThemeDark');
    if (hlLight && hlDark) {
      hlLight.disabled = THEME_MODES[flavor] === 'dark';
      hlDark.disabled = THEME_MODES[flavor] !== 'dark';
    }
  }

  $('#themeToggle').addEventListener('click', e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    try {
      localStorage.setItem(THEME_KEY, btn.dataset.themeOpt);
    } catch {}
    applyTheme(btn.dataset.themeOpt);
  });

  // Follow the OS when the choice is "system".
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme(storedTheme()));
  applyTheme(storedTheme());

  // Accent color: one CSS variable drives every tint via color-mix().
  const ACCENT_KEY = 'memoir-accent';

  function applyAccent(color) {
    if (!/^#[0-9a-fA-F]{6}$/.test(color)) return;
    document.documentElement.style.setProperty('--accent', color);
    $$('#accentRow button').forEach(btn =>
      btn.classList.toggle('active', btn.dataset.accent.toLowerCase() === color.toLowerCase()));
  }

  $('#accentRow').addEventListener('click', e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    try {
      localStorage.setItem(ACCENT_KEY, btn.dataset.accent);
    } catch {}
    applyAccent(btn.dataset.accent);
  });

  (() => {
    let saved = '#6F5EE8';
    try {
      saved = localStorage.getItem(ACCENT_KEY) || saved;
    } catch {}
    applyAccent(saved);
  })();

  // Settings navigation: one pane visible at a time.
  $('.settings-nav').addEventListener('click', e => {
    const btn = e.target.closest('button[data-pane]');
    if (!btn) return;
    $$('.settings-nav button').forEach(x => x.classList.toggle('active', x === btn));
    $$('.settings-panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== btn.dataset.pane));
    $('#saveSettings').classList.toggle('hidden', btn.dataset.pane === 'updates' || btn.dataset.pane === 'activity');
    if (btn.dataset.pane === 'activity') loadGlobalActivity();
  });

  function formatActivityDate(value) {
    try {
      return new Date(value.replace(' ', 'T')).toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
      });
    } catch {
      return value || '';
    }
  }

  async function loadGlobalActivity() {
    const list = $('#globalActivityList');
    list.innerHTML = '<span class="history-empty">Loading activity…</span>';
    try {
      const d = await api('activity');
      if (!d.activity.length) {
        list.innerHTML = '<span class="history-empty">No activity yet.</span>';
        return;
      }
      list.innerHTML = d.activity.map(entry => `
        <div class="activity-row">
          <strong>${escapeHtml(entry.note_title || 'Untitled note')}</strong>
          <span>${escapeHtml(entry.message)}</span>
          <span class="activity-date">${escapeHtml(formatActivityDate(entry.created_at))}</span>
        </div>`).join('');
    } catch (err) {
      list.innerHTML = `<span class="history-empty">${escapeHtml(err.message)}</span>`;
    }
  }

  // ---------------------------------------------------------------------
  // Change password
  // ---------------------------------------------------------------------

  $('#changePassword').onclick = async () => {
    const status = $('#pwStatus');
    const current = $('#pwCurrent').value;
    const next = $('#pwNew').value;
    const confirmed = $('#pwConfirm').value;

    status.className = 'pw-status error';
    if (next.length < 12) {
      status.textContent = 'New password must be at least 12 characters.';
      return;
    }
    if (next !== confirmed) {
      status.textContent = 'New passwords do not match.';
      return;
    }
    try {
      await api('change-password', {
        method: 'POST',
        body: JSON.stringify({ current, password: next }),
      });
      status.className = 'pw-status ok';
      status.textContent = 'Password updated.';
      $('#pwCurrent').value = $('#pwNew').value = $('#pwConfirm').value = '';
    } catch (e) {
      status.textContent = e.message;
    }
  };

  // ---------------------------------------------------------------------
  // URL state: the current view lives in query params, so a reload (or a
  // bookmarked/shared link) restores the same note, filter, and search.
  // ---------------------------------------------------------------------

  // Write the current view into the query string. push=true adds a history
  // entry (a navigation the back button can return to); otherwise the URL is
  // replaced in place (typing in search, autosave refreshes, boot).
  function syncUrl(push = false) {
    const params = new URLSearchParams();
    if (current) params.set('note', current.id);
    if (trashView) params.set('trash', '1');
    else if (filterTag !== '') params.set('tag', filterTag);
    else if (filterFolder !== '') params.set('folder', filterFolder);
    if (pinnedOnly && !trashView) params.set('pinned', '1');
    const q = searchInput.value.trim();
    if (q) params.set('q', q);
    if (advancedFilters.scope !== 'all') params.set('scope', advancedFilters.scope);
    if (advancedFilters.pinned !== '') params.set('pin', advancedFilters.pinned);
    if (advancedFilters.state !== '') params.set('state', advancedFilters.state);
    if (advancedFilters.after) params.set('after', advancedFilters.after);
    if (advancedFilters.before) params.set('before', advancedFilters.before);

    const qs = params.toString();
    const newSearch = qs ? `?${qs}` : '';
    if (newSearch === location.search) return;
    const target = newSearch || location.pathname;
    if (push) history.pushState(null, '', target);
    else history.replaceState(null, '', target);
  }

  // Apply whatever the URL says: filters, search, and the open note.
  // Used on boot (reload / bookmarked link) and on back/forward navigation.
  function applyUrlState() {
    const params = new URLSearchParams(location.search);
    searchInput.value = params.get('q') || '';
    filterFolder = params.get('folder') || '';
    filterTag = params.get('tag') || '';
    pinnedOnly = params.get('pinned') === '1';
    trashView = params.get('trash') === '1';
    advancedFilters.scope = ['title', 'content', 'tags'].includes(params.get('scope')) ? params.get('scope') : 'all';
    advancedFilters.pinned = ['0', '1'].includes(params.get('pin')) ? params.get('pin') : '';
    advancedFilters.state = ['active', 'trash', 'all'].includes(params.get('state')) ? params.get('state') : '';
    advancedFilters.after = /^\d{4}-\d{2}-\d{2}$/.test(params.get('after') || '') ? params.get('after') : '';
    advancedFilters.before = /^\d{4}-\d{2}-\d{2}$/.test(params.get('before') || '') ? params.get('before') : '';
    syncAdvancedFilterControls();
    applyTrashModeUi();

    clearFilterHighlights();
    if (trashView) {
      $('.nav-item[data-trash="1"]')?.classList.add('active');
      $('#listTitle').textContent = 'Trash';
    } else if (filterTag) {
      $(`.tag-item[data-tag="${CSS.escape(filterTag)}"]`)?.classList.add('active');
      $('#listTitle').textContent = `#${filterTag}`;
    } else if (filterFolder) {
      const btn = $(`.folder-item[data-folder="${CSS.escape(filterFolder)}"]`);
      btn?.classList.add('active');
      $('#listTitle').textContent = btn ? btn.querySelector('span').textContent : 'All notes';
    } else if (pinnedOnly) {
      $('.nav-item[data-pinned="1"]')?.classList.add('active');
      $('#listTitle').textContent = 'Pinned';
    } else {
      $('.nav-item[data-folder=""]')?.classList.add('active');
      $('#listTitle').textContent = 'All notes';
    }

    refreshList();

    const noteId = parseInt(params.get('note') || '', 10);
    if (noteId && (!current || current.id != noteId)) {
      // The note may have been deleted since the URL was made; just drop it.
      loadNote(noteId, false).catch(() => syncUrl());
    } else if (!noteId && current) {
      closeEditor();
    }
  }

  window.addEventListener('popstate', applyUrlState);
  if (location.search) applyUrlState();

})();
