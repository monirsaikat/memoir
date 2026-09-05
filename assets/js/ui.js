(() => {
  'use strict';

  // UI behavior lives alongside the editor so note and persistence logic stay
  // owned by app.js. Its existing dialog open/close handlers remain in charge.
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const visible = element => !!element && element.isConnected
    && element.getClientRects().length > 0
    && getComputedStyle(element).visibility !== 'hidden';
  const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';
  const focusables = root => $$(focusableSelector, root).filter(element =>
    element.tabIndex >= 0 && !element.closest('[inert]') && visible(element));

  function focusInside(root) {
    if (!root) return;
    const target = focusables(root)[0] || $('[role="dialog"]', root) || root;
    if (!target.hasAttribute('tabindex') && target.tabIndex < 0) target.tabIndex = -1;
    target.focus({ preventScroll: true });
  }

  function returnTarget(element) {
    if (!(element instanceof HTMLElement)) return null;
    // Actions inside a closed disclosure cannot receive focus. Returning to
    // its summary keeps keyboard users at the control that opened the action.
    const disclosure = element.closest('details:not([open])');
    if (disclosure) element = $('summary', disclosure);
    return visible(element) && !element.hasAttribute('disabled') ? element : null;
  }

  const isApple = /Mac|iPhone|iPad|iPod/.test(navigator.userAgentData?.platform || navigator.platform || '');
  const modifier = isApple ? '\u2318' : 'Ctrl';
  $$('.search-wrap > kbd, .empty-shortcut kbd').forEach(key => { key.textContent = `${modifier} K`; });
  $$('.new-note-btn kbd').forEach(key => { key.textContent = `${modifier} N`; });
  $('#globalSearch')?.setAttribute('aria-keyshortcuts', `${isApple ? 'Meta' : 'Control'}+K`);
  $('#newNote')?.setAttribute('aria-keyshortcuts', `${isApple ? 'Meta' : 'Control'}+N`);

  // Title tooltips are useful on pointer devices; explicit names also make
  // icon buttons understandable to assistive technology on touch screens.
  const namedFromTitle = new WeakSet();
  function nameIconButton(button) {
    if (!button.textContent.trim() && button.title
        && (!button.hasAttribute('aria-label') || namedFromTitle.has(button))) {
      button.setAttribute('aria-label', button.title);
      namedFromTitle.add(button);
    }
  }
  $$('button[title]').forEach(nameIconButton);
  const titleObserver = new MutationObserver(records => records.forEach(record => nameIconButton(record.target)));
  $$('.focus-toggle').forEach(button => titleObserver.observe(button, { attributes: true, attributeFilter: ['title'] }));

  function closeNoteOptions(restoreFocus = false) {
    $$('.note-options[open]').forEach(menu => {
      menu.open = false;
      if (restoreFocus) $('summary', menu)?.focus({ preventScroll: true });
    });
  }
  document.addEventListener('pointerdown', event => {
    if (!event.target.closest('.note-options')) closeNoteOptions();
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('.note-options') || event.target.closest('.note-options button')) closeNoteOptions();
  });
  $$('.note-options').forEach(menu => {
    const summary = $('summary', menu);
    summary?.setAttribute('aria-expanded', String(menu.open));
    menu.addEventListener('toggle', () => summary?.setAttribute('aria-expanded', String(menu.open)));
  });
  $('#createFirstNote')?.addEventListener('click', () => $('#newNote')?.click());

  $('.editor-body')?.addEventListener('scroll', () => {
    $$('#formatBubble, #tableMenu, #colorSheet, #headingSheet').forEach(popover => popover.classList.add('hidden'));
    closeNoteOptions();
  }, { passive: true });

  // Observe class changes because existing app handlers open settings,
  // history, and the quick switcher without dispatching lifecycle events.
  const dialogRoots = $$('.modal-backdrop, .palette-backdrop');
  const dialogStack = [];
  const dialogOpeners = new WeakMap();
  const dialogIsOpen = root => !root.classList.contains('hidden') && !root.hidden;
  let lastFocusedElement = document.activeElement;
  let previousFocusedElement = document.activeElement;
  let redirectingFocus = false;
  let sidebarReturn = null;
  let sidebarWasOpen = false;
  const sidebar = $('.sidebar');
  const drawerMedia = matchMedia('(max-width: 1100px)');
  const drawerOpen = () => drawerMedia.matches && document.body.classList.contains('sidebar-open');
  const activeTrap = () => dialogStack.at(-1) || (drawerOpen() ? sidebar : null);

  function syncDialogs() {
    const previousTop = dialogStack.at(-1);
    let opened = false;
    dialogRoots.forEach(root => {
      const index = dialogStack.indexOf(root);
      if (dialogIsOpen(root) && index === -1) {
        // The quick switcher focuses synchronously before observers run.
        const opener = root.contains(document.activeElement) ? previousFocusedElement : document.activeElement;
        dialogOpeners.set(root, opener);
        dialogStack.push(root);
        $('[role="dialog"]', root)?.setAttribute('aria-modal', 'true');
        opened = true;
      } else if (!dialogIsOpen(root) && index !== -1) {
        dialogStack.splice(index, 1);
      }
    });
    const top = dialogStack.at(-1);
    if (opened) {
      // app.js also schedules initial focus. Running after that timer lets us
      // correct an unavailable first control without fighting its behavior.
      setTimeout(() => {
        if (top === dialogStack.at(-1) && top
            && (!top.contains(document.activeElement) || !visible(document.activeElement))) focusInside(top);
      }, 0);
    } else if (previousTop && !dialogStack.includes(previousTop)) {
      const target = returnTarget(dialogOpeners.get(previousTop));
      if (target && (!top || top.contains(target))) target.focus({ preventScroll: true });
      else if (top) focusInside(top);
      else if (drawerOpen()) focusInside(sidebar);
      else returnTarget($('#noteTitle'))?.focus({ preventScroll: true });
    }
  }
  const dialogObserver = new MutationObserver(syncDialogs);
  dialogRoots.forEach(root => dialogObserver.observe(root, { attributes: true, attributeFilter: ['class', 'hidden'] }));

  function syncSidebar() {
    const open = drawerOpen();
    if (sidebar) {
      sidebar.inert = drawerMedia.matches && !open;
      if (drawerMedia.matches && !open) sidebar.setAttribute('aria-hidden', 'true');
      else sidebar.removeAttribute('aria-hidden');
    }
    $$('.navigation-toggle').forEach(button => {
      button.setAttribute('aria-expanded', String(open));
      if (sidebar) button.setAttribute('aria-controls', sidebar.id);
    });
    if (open && !sidebarWasOpen) {
      sidebarReturn = document.activeElement;
      if (!dialogStack.length) focusInside(sidebar);
    } else if (!open && sidebarWasOpen && !dialogStack.length) {
      const target = returnTarget(sidebarReturn) || returnTarget($('.navigation-toggle'));
      // Desktop resize keeps the sidebar visible, so it needs no focus jump.
      if (drawerMedia.matches) target?.focus({ preventScroll: true });
    }
    sidebarWasOpen = open;
  }
  if (sidebar && !sidebar.id) sidebar.id = 'workspaceNavigation';
  const sidebarObserver = new MutationObserver(syncSidebar);
  sidebarObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
  drawerMedia.addEventListener('change', () => {
    if (!drawerMedia.matches) document.body.classList.remove('sidebar-open');
    syncSidebar();
  });

  // Capture only focus containment and layer-local dismissal. Let app.js
  // receive Escape for dialogs so it can perform its existing cleanup.
  document.addEventListener('keydown', event => {
    const trap = activeTrap();
    if (event.key === 'Escape' && !dialogStack.length) {
      if ($('.note-options[open]')) {
        closeNoteOptions(true);
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      if (drawerOpen()) {
        document.body.classList.remove('sidebar-open');
        event.preventDefault();
        event.stopPropagation();
        return;
      }
    }
    if (!trap) return;
    // Workspace shortcuts must not create or switch notes behind a dialog.
    if (dialogStack.length && (event.ctrlKey || event.metaKey) && ['k', 'n', 'p', 's'].includes(event.key.toLowerCase())) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (event.key !== 'Tab') return;
    const elements = focusables(trap);
    const first = elements[0];
    const last = elements.at(-1);
    if (!first) {
      event.preventDefault();
      focusInside(trap);
    } else if (!trap.contains(document.activeElement)
        || (event.shiftKey && document.activeElement === first)
        || (!event.shiftKey && document.activeElement === last)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    }
  }, true);
  document.addEventListener('focusin', event => {
    previousFocusedElement = lastFocusedElement;
    lastFocusedElement = event.target;
    const trap = activeTrap();
    if (trap && !trap.contains(event.target) && !redirectingFocus) {
      redirectingFocus = true;
      focusInside(trap);
      redirectingFocus = false;
    }
  });

  syncDialogs();
  syncSidebar();
})();
