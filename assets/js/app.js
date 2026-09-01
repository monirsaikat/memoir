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
  let pinnedOnly = false;    // "Pinned" nav filter
  let saveTimer = null;      // debounce timer for autosave
  let draftStyle = { icon: 'fa-note-sticky', color: '#6F5EE8' };

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
    <div class="note-meta"><span>${escapeHtml(n.folder_name || 'Unfiled')}</span><time>${fmtDate(n.updated_at)}</time></div></button>`).join('');
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
      content: $('#noteContent').innerHTML,
      icon: draftStyle.icon,
      color: draftStyle.color,
      is_pinned: current.is_pinned,
    };
    try {
      await api('save-note', { method: 'POST', body: JSON.stringify(body) });
      $('#saveStatus').textContent = 'Saved';
      await refreshList();
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
  $('#noteContent').addEventListener('input', () => { queueSave(); updateWords(); });

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

  $$('.nav-item').forEach(btn => btn.onclick = () => {
    filterFolder = btn.dataset.folder ?? '';
    pinnedOnly = btn.dataset.pinned === '1';
    $$('.nav-item,.folder-item').forEach(x => x.classList.remove('active'));
    btn.classList.add('active');
    $('#listTitle').textContent = pinnedOnly ? 'Pinned' : 'All notes';
    closeSidebar();
    refreshList();
  });

  $('#folderList').addEventListener('click', e => {
    const btn = e.target.closest('.folder-item');
    if (!btn) return;
    filterFolder = btn.dataset.folder;
    pinnedOnly = false;
    $$('.nav-item,.folder-item').forEach(x => x.classList.remove('active'));
    btn.classList.add('active');
    $('#listTitle').textContent = btn.querySelector('span').textContent;
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

  $$('.toolbar [data-cmd]').forEach(btn => btn.onclick = () => {
    document.execCommand(btn.dataset.cmd, false, btn.dataset.value || null);
    $('#noteContent').focus();
    queueSave();
  });

  $$('.toolbar [data-block]').forEach(btn => btn.onclick = () => {
    document.execCommand('formatBlock', false, btn.dataset.block);
    $('#noteContent').focus();
    queueSave();
  });

  $('#insertLink').onclick = () => {
    const url = prompt('Paste URL');
    if (url) document.execCommand('createLink', false, url);
  };

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
