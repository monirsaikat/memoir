{*
    Workspace sidebar: brand, primary navigation, folders, tags and the
    bottom actions (what's new, settings, sign out).
    Included by pages/workspace/index.tpl.
*}
    <!-- Sidebar: brand, navigation, folders, settings -->
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true"><i class="fa-solid fa-book-open"></i></span>
            <span class="brand-name">{$appName}<small>Your personal workspace</small></span>
            <button class="icon-btn navigation-close sidebar-close" id="closeSidebar" type="button" aria-label="Close navigation">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <button class="new-note-btn" id="newNote"><i class="fa-solid fa-plus"></i><span>New note</span><kbd>N</kbd></button>

        <div class="sidebar-scroll">
        <nav class="main-nav" aria-label="Workspace">
            <button class="nav-item active" data-folder="">
                <i class="fa-regular fa-note-sticky"></i>
                <span>All notes</span>
                <span class="count">{$notes|count}</span>
            </button>
            <button class="nav-item" data-pinned="1">
                <i class="fa-solid fa-thumbtack"></i>
                <span>Pinned</span>
            </button>
            <button class="nav-item" data-trash="1">
                <i class="fa-regular fa-trash-can"></i>
                <span>Trash</span>
                <span class="count" id="trashCount">{$trashCount}</span>
            </button>
        </nav>

        {if $userRole === 'owner'}
        <div class="section-title">
            <span>Folders</span>
            <button id="addFolder" title="Add folder"><i class="fa-solid fa-plus"></i></button>
        </div>

        <div id="folderList" class="folder-list">
            {foreach $folders as $folder}
            <div class="folder-row">
                <button class="folder-item" data-folder="{$folder.id}">
                    <i class="fa-solid {$folder.icon}" style="color:{$folder.color}"></i>
                    <span>{$folder.name}</span>
                    <span class="count">{$folder.note_count}</span>
                </button>
                <button class="folder-menu-btn" data-folder="{$folder.id}" type="button" aria-label="Folder options">
                    <i class="fa-solid fa-ellipsis"></i>
                </button>
            </div>
            {/foreach}
        </div>
        {/if}

        <div class="section-title" id="tagSectionTitle"{if !$tagCounts} hidden{/if}><span>Tags</span></div>
        <div id="tagList" class="tag-list">
            {foreach $tagCounts as $tag => $count}
            <button class="tag-item" data-tag="{$tag}">#{$tag}<span class="count">{$count}</span></button>
            {/foreach}
        </div>
        </div>

        <div class="sidebar-bottom">
            <button id="whatsNewBtn">
                <i class="fa-regular fa-circle-question"></i> What’s new
                <span class="version-pill">v{$version}</span>
            </button>
            <button id="settingsBtn"><i class="fa-solid fa-sliders"></i> Settings</button>
            <form method="post" action="logout.php">
                <input type="hidden" name="_csrf" value="{$csrf}">
                <button type="submit"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</button>
            </form>
        </div>
    </aside>
