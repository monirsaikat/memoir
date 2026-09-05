{*
    Workspace middle panel: list heading, search box with the advanced-search
    panel, the note cards (or the empty state) and the bulk-action bar.
    Included by pages/workspace/index.tpl. Each $notes row carries a
    precomputed `_preview` (see index.php).
*}
    <!-- Middle panel: searchable note list -->
    <section class="note-list-panel" aria-label="Notes">
        <div class="list-head">
            <div>
                <h1 id="listTitle">All notes</h1>
                <span id="listCount">{$notes|count} notes</span>
            </div>
            <div class="list-head-actions">
                <button id="focusToggleList" class="icon-btn focus-toggle" type="button" title="Hide sidebar">
                    <i class="fa-solid fa-angles-left"></i>
                </button>
                <button id="sortBtn" class="icon-btn" type="button" title="Sort notes">
                    <i class="fa-solid fa-arrow-down-wide-short"></i>
                </button>
                <button id="selectModeBtn" class="icon-btn" type="button" title="Select notes">
                    <i class="fa-solid fa-check-double"></i>
                </button>
                <button id="collapseSidebar" class="icon-btn navigation-toggle" type="button" aria-label="Open navigation" aria-expanded="false">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
        </div>

        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input id="globalSearch" type="search" name="memoir_note_search" placeholder="Search notes"
                   aria-label="Search notes" autocomplete="off" autocapitalize="off" spellcheck="false"
                   readonly data-1p-ignore data-lpignore="true" data-form-type="other">
            <button type="button" id="searchFilterBtn" class="search-filter-btn" aria-label="Advanced search" title="Advanced search">
                <i class="fa-solid fa-sliders"></i>
            </button>
            <kbd>⌘ K</kbd>
            <div class="search-filter-panel hidden" id="searchFilterPanel">
                <div class="search-filter-head">
                    <strong>Advanced search</strong>
                    <button type="button" id="clearSearchFilters">Clear</button>
                </div>
                <label>Search in
                    <select id="searchScope">
                        <option value="all">Title, content, and tags</option>
                        <option value="title">Title only</option>
                        <option value="content">Content only</option>
                        <option value="tags">Tags only</option>
                    </select>
                </label>
                <div class="search-filter-grid">
                    <label>Pin status
                        <select id="searchPinned">
                            <option value="">Any</option>
                            <option value="1">Pinned</option>
                            <option value="0">Not pinned</option>
                        </select>
                    </label>
                    <label>Location
                        <select id="searchState">
                            <option value="">Current view</option>
                            <option value="active">Active notes</option>
                            <option value="trash">Trash</option>
                            <option value="all">Active and Trash</option>
                        </select>
                    </label>
                    <label>Updated after<input id="searchAfter" type="date"></label>
                    <label>Updated before<input id="searchBefore" type="date"></label>
                </div>
                <p>Power search: <code>tag:work</code> <code>folder:"Ideas"</code> <code>is:pinned</code> <code>before:2026-09-01</code> <code>in:title</code></p>
                <button type="button" class="primary-btn search-apply" id="applySearchFilters">Apply filters</button>
            </div>
        </div>
        <div class="active-search-filters hidden" id="activeSearchFilters"></div>

        <div class="list-section-label"><span>Your notes</span><span>Updated</span></div>
        <div id="noteList" class="note-list">
            {if !$notes}
            <div class="list-empty">
                <i class="fa-regular fa-compass"></i>
                <strong>No notes yet</strong>
                <span>Create your first note to get started.</span>
            </div>
            {/if}

            {foreach $notes as $note}
            <button class="note-card" data-id="{$note.id}" data-folder="{$note.folder_id|default:''}" data-pinned="{$note.is_pinned}">
                <div class="note-card-heading">
                    <span class="note-glyph"><i class="fa-solid {$note.icon}" style="color:{if $note.color === '#FFFFFF'}#6f5ee8{else}{$note.color}{/if}"></i></span>
                    <strong>{$note.title}</strong>
                    {if $note.is_pinned}<i class="fa-solid fa-thumbtack pin-mini" aria-label="Pinned"></i>{/if}
                </div>
                <p>{if $note._preview}{$note._preview}{else}<span class="note-preview-empty">No content yet</span>{/if}</p>
                <div class="note-meta">
                    <span>{$note.folder_name|default:'Unfiled'}{if ($note.tags|default:'') !== ''} · #{$note.tags|replace:',':' #'}{/if}</span>
                    <time>{$note.updated_at|date:'M j'}</time>
                </div>
            </button>
            {/foreach}
        </div>

        <!-- Bulk actions shown while selecting notes -->
        <div class="bulk-bar hidden" id="bulkBar">
            <div class="bulk-summary">
                <span id="bulkCount">0 selected</span>
                <button type="button" id="bulkCancel" aria-label="Cancel selection" title="Cancel selection"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="bulk-actions">
                <button type="button" id="bulkSelectAll"><i class="fa-solid fa-check-double"></i> Select all</button>
                <button type="button" id="bulkRestore" class="hidden"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                <button type="button" id="bulkDelete" class="bulk-danger"><i class="fa-regular fa-trash-can"></i> <span id="bulkDeleteLabel">Delete</span></button>
            </div>
        </div>
    </section>
