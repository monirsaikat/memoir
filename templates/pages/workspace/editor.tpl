{*
    Workspace editor panel: empty state, trash banner, editor header and
    actions, title/tag inputs, formatting toolbar with the heading and colour
    sheets, the rich-text area, linked/unlinked references, footer, plus the
    floating format bubble and table menu.
    Included by pages/workspace/index.tpl.
*}
    <!-- Right panel: note editor -->
    <main class="editor-panel">
        <div id="emptyState" class="empty-state">
            <button id="focusToggleEmpty" class="icon-btn focus-toggle empty-state-focus-toggle" type="button" title="Show sidebar">
                <i class="fa-solid fa-angles-right"></i>
            </button>
            <div class="empty-icon"><i class="fa-regular fa-pen-to-square"></i></div>
            <span class="empty-eyebrow">A little space for your ideas</span>
            <h2>Make room for a thought.</h2>
            <p>Pick up where you left off, or start with a blank page. This space is yours.</p>
            <button class="empty-create" id="createFirstNote" type="button"><i class="fa-solid fa-plus"></i> Create a note</button>
            <span class="empty-shortcut">Find a note with <kbd>Ctrl K</kbd></span>
        </div>

        <div id="editorView" class="editor-view hidden">
            <div class="trash-banner hidden" id="trashBanner">
                <i class="fa-regular fa-trash-can"></i>
                <span>This note is in the Trash.</span>
                <button type="button" id="restoreNote">Restore</button>
                <button type="button" id="destroyNote" class="danger">Delete forever</button>
            </div>
            <header class="editor-head">
                <button class="icon-btn mobile-only" id="backToList" type="button" aria-label="Back to notes">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <button class="icon-btn focus-toggle" id="focusToggleEditor" type="button" title="Hide sidebar">
                    <i class="fa-solid fa-angles-left"></i>
                </button>
                <div class="crumb">
                    <button type="button" class="crumb-btn" id="crumbFolder" title="Move to folder">Unfiled</button>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span id="saveStatus" role="status" aria-live="polite">Saved</span>
                </div>
                <div class="editor-actions">
                    <button class="icon-btn share-note-btn" id="shareNote" title="Share note" aria-label="Share note"><i class="fa-solid fa-arrow-up-from-bracket"></i><span>Share</span></button>
                    <button class="icon-btn" id="pinNote" title="Pin" aria-label="Pin note"><i class="fa-solid fa-thumbtack"></i></button>
                    <details class="note-options">
                        <summary class="icon-btn" aria-label="More note actions" title="More note actions"><i class="fa-solid fa-ellipsis"></i></summary>
                        <div class="note-options-menu">
                            <span class="menu-caption">This note</span>
                            <button type="button" id="historyNote"><i class="fa-solid fa-clock-rotate-left"></i> Version history</button>
                            <button type="button" id="activityNote"><i class="fa-solid fa-list-check"></i> Activity</button>
                            <button type="button" id="noteStyle"><i class="fa-solid fa-palette"></i> Appearance</button>
                            <button type="button" class="danger" id="deleteNote"><i class="fa-regular fa-trash-can"></i> Move to trash</button>
                        </div>
                    </details>
                </div>
            </header>

            <div class="editor-body">
                <div class="note-cover" id="noteCover"></div>
                <div class="document-heading">
                    <span class="document-eyebrow"><i class="fa-regular fa-file-lines"></i> NOTE</span>
                    <textarea id="noteTitle" class="title-input" rows="1" placeholder="Untitled note" aria-label="Note title"></textarea>
                </div>

                <div class="tag-row">
                    <i class="fa-solid fa-tag"></i>
                    <div class="tag-chips" id="tagChips"></div>
                    <input id="tagInput" placeholder="Add a tag…" aria-label="Add a tag" maxlength="30" autocomplete="off">
                </div>

                <div class="toolbar-wrap">
                    <div class="toolbar" role="toolbar" aria-label="Text formatting">
                        <div class="tool-group">
                            <button type="button" data-cmd="undo" title="Undo (Ctrl+Z)"><i class="fa-solid fa-rotate-left"></i></button>
                            <button type="button" data-cmd="redo" title="Redo (Ctrl+Y)"><i class="fa-solid fa-rotate-right"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" data-cmd="bold" data-state="bold" title="Bold (Ctrl+B or **text**)"><i class="fa-solid fa-bold"></i></button>
                            <button type="button" data-cmd="italic" data-state="italic" title="Italic (Ctrl+I or *text*)"><i class="fa-solid fa-italic"></i></button>
                            <button type="button" data-cmd="underline" data-state="underline" title="Underline (Ctrl+U)"><i class="fa-solid fa-underline"></i></button>
                            <button type="button" data-cmd="strikeThrough" data-state="strikeThrough" title="Strikethrough (~~text~~)"><i class="fa-solid fa-strikethrough"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" class="tool-label" id="headingBtn" title="Headings (# … ###### + space)">
                                <span id="headingLabel">H</span><i class="fa-solid fa-chevron-down heading-caret"></i>
                            </button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" data-cmd="insertUnorderedList" data-state="insertUnorderedList" title="Bullet list (- + space)"><i class="fa-solid fa-list-ul"></i></button>
                            <button type="button" data-cmd="insertOrderedList" data-state="insertOrderedList" title="Numbered list (1. + space)"><i class="fa-solid fa-list-ol"></i></button>
                            <button type="button" id="checklistBtn" title="Task list ([] + space)"><i class="fa-solid fa-list-check"></i></button>
                            <button type="button" data-cmd="formatBlock" data-value="blockquote" title="Quote (&gt; + space)"><i class="fa-solid fa-quote-left"></i></button>
                            <button type="button" data-cmd="formatBlock" data-value="pre" title="Code block (``` + Enter)"><i class="fa-solid fa-code"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" id="textColorBtn" title="Text color"><i class="fa-solid fa-font"></i><span class="color-bar" id="textColorBar"></span></button>
                            <button type="button" id="highlightBtn" title="Highlight"><i class="fa-solid fa-highlighter"></i><span class="color-bar" id="highlightBar"></span></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" id="insertLink" title="Insert link"><i class="fa-solid fa-link"></i></button>
                            <button type="button" id="insertImage" title="Insert image"><i class="fa-regular fa-image"></i></button>
                            <button type="button" id="insertTableBtn" title="Insert table"><i class="fa-solid fa-table"></i></button>
                            <button type="button" data-cmd="insertHorizontalRule" title="Divider (--- + Enter)"><i class="fa-solid fa-minus"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" data-cmd="removeFormat" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
                        </div>
                        <input type="file" id="imageInput" accept="image/*" hidden>
                    </div>

                    <!-- Heading picker; anchored below the H button by JS -->
                    <div class="heading-sheet hidden" id="headingSheet" role="menu">
                        <button type="button" data-h="p"><span class="hs-normal">Normal text</span></button>
                        {for $level=1 to 6}
                        <button type="button" data-h="h{$level}">
                            <span class="hs-h{$level}">Heading {$level}</span>
                            <kbd>{'#'|str_repeat:$level}</kbd>
                        </button>
                        {/for}
                    </div>

                    <!-- Color picker sheet; anchored below its toolbar button by JS -->
                    <div class="color-sheet hidden" id="colorSheet" role="menu">
                        <div class="color-sheet-title" id="colorSheetTitle">Text color</div>
                        <div class="swatch-row" id="colorSwatches"></div>
                        <button type="button" class="swatch-clear" id="colorClear">Remove color</button>
                    </div>
                </div>

                <div id="noteContent" class="rich-editor" contenteditable="true" spellcheck="true" role="textbox" aria-label="Note content" aria-multiline="true"></div>

                <div class="references hidden" id="backlinks">
                    <section class="reference-group hidden" id="linkedReferences">
                        <div class="reference-heading">
                            <span><i class="fa-solid fa-arrow-turn-up"></i> Linked references</span>
                            <span class="reference-count" id="linkedReferenceCount">0</span>
                        </div>
                        <div class="reference-list" id="backlinkList"></div>
                    </section>
                    <section class="reference-group hidden" id="unlinkedReferences">
                        <div class="reference-heading">
                            <span><i class="fa-solid fa-magnifying-glass"></i> Unlinked mentions</span>
                            <span class="reference-count" id="unlinkedReferenceCount">0</span>
                        </div>
                        <p class="reference-help">These notes mention this title in plain text but do not link to it yet.</p>
                        <div class="reference-list" id="unlinkedMentionList"></div>
                    </section>
                </div>
            </div>

            <footer class="editor-foot">
                <span id="wordCount">0 words</span>
                <span id="updatedAt"></span>
            </footer>
        </div>

        <!-- Floating format bubble shown over selected text -->
        <div class="format-bubble hidden" id="formatBubble" role="toolbar" aria-label="Format selection">
            <button type="button" data-bcmd="bold" data-bstate="bold" title="Bold"><i class="fa-solid fa-bold"></i></button>
            <button type="button" data-bcmd="italic" data-bstate="italic" title="Italic"><i class="fa-solid fa-italic"></i></button>
            <button type="button" data-bcmd="underline" data-bstate="underline" title="Underline"><i class="fa-solid fa-underline"></i></button>
            <button type="button" data-bcmd="strikeThrough" data-bstate="strikeThrough" title="Strikethrough"><i class="fa-solid fa-strikethrough"></i></button>
            <span class="b-sep"></span>
            <button type="button" id="bubbleLink" title="Link"><i class="fa-solid fa-link"></i></button>
            <button type="button" id="bubbleHighlight" title="Highlight"><i class="fa-solid fa-highlighter"></i></button>
            <button type="button" data-bcmd="removeFormat" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
        </div>

        <!-- Table tools shown while the caret is inside a table -->
        <div class="table-menu hidden" id="tableMenu" role="toolbar" aria-label="Table tools">
            <button type="button" data-tbl="addRow" title="Add row below">+ Row</button>
            <button type="button" data-tbl="addCol" title="Add column right">+ Col</button>
            <button type="button" data-tbl="delRow" title="Delete row">&minus; Row</button>
            <button type="button" data-tbl="delCol" title="Delete column">&minus; Col</button>
            <span class="b-sep"></span>
            <button type="button" data-tbl="delTable" title="Delete table"><i class="fa-regular fa-trash-can"></i></button>
        </div>
    </main>
