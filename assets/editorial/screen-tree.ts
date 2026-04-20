/**
 * Screen tree manager — drag-reorder + drag-nest + inline CRUD.
 *
 * Hydrates the server-rendered nested <ol>s in the "Screens & Navigation"
 * metabox with SortableJS. Every drag-end and every CRUD action POSTs
 * incrementally to ScreensTreeRoute; the metabox itself has no Save
 * button so a tab close mid-edit never loses an arrangement.
 *
 * DOM contract (rendered by EditorialController::renderScreenList):
 *   .mua-screen-tree[data-app-id]
 *     .mua-screen-tree__toolbar > button.mua-screen-tree__add[data-parent-id="0"]
 *     ol.mua-screen-tree__list[data-parent-id="0"]
 *       li.mua-screen-tree__item[data-screen-id]
 *         .mua-screen-tree__row > button.mua-screen-tree__add[data-parent-id]
 *                                 button.mua-screen-tree__delete
 *         ol.mua-screen-tree__list[data-parent-id]   (recursive)
 *
 * Two non-obvious wiring details:
 *  1. We wire click handlers unconditionally — even if `window.wp.apiFetch`
 *     isn't ready at DOMContentLoaded, users could click sooner than
 *     Gutenberg finishes booting. Lazy-resolve apiFetch inside each handler.
 *  2. We attach our own REST-nonce middleware from the localized
 *     `MUA_EDITORIAL.nonce`. Gutenberg normally attaches one during its
 *     init, but that runs asynchronously and is racy with our init; a
 *     missing nonce → 401/403 with no visible clue.
 */

import Sortable from 'sortablejs';

// The global `window.wp` shape is declared in editorial/init.ts with
// `apiFetch?: any`, so we narrow here locally rather than redeclaring it.

// eslint-disable-next-line @typescript-eslint/no-explicit-any
interface ApiFetchWithMiddleware {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (options: { path: string; method: string; data?: any }): Promise<any>;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    use?: (middleware: any) => void;
    createNonceMiddleware?: (nonce: string) => unknown;
}

export interface TreeRow {
    id: number;
    parent: number;
    order: number;
}

let nonceAttached = false;

/**
 * Attempt to resolve `wp.apiFetch` and ensure it carries a REST nonce.
 * Returns null if apiFetch still isn't present (the page hasn't loaded
 * wp-api-fetch for some reason). Safe to call from inside click handlers.
 */
function resolveApiFetch(): ApiFetchWithMiddleware | null {
    const apiFetch = window.wp?.apiFetch as ApiFetchWithMiddleware | undefined;
    if (!apiFetch) return null;

    if (!nonceAttached && window.MUA_EDITORIAL?.nonce && apiFetch.use && apiFetch.createNonceMiddleware) {
        apiFetch.use(apiFetch.createNonceMiddleware(window.MUA_EDITORIAL.nonce));
        nonceAttached = true;
    }

    return apiFetch;
}

function missingApiFetchMessage(): string {
    return 'The block editor APIs did not finish loading on this page. Reload and try again.';
}

/**
 * Tracks `.mua-screen-tree` containers that have already had Sortable
 * attached, so re-hydration after a Gutenberg metabox re-insert doesn't
 * double-bind. WeakSet lets the entries GC naturally once React drops
 * the old DOM nodes.
 */
const hydratedContainers = new WeakSet<HTMLElement>();
const hydratedLists = new WeakSet<HTMLOListElement>();

export function initScreenTree(root: ParentNode = document): void {
    // Click handlers are document-delegated (see wireAddButtons /
    // wireDeleteButtons) so they survive Gutenberg's metabox re-insert;
    // bind them once at init time regardless of whether the tree is
    // present yet.
    wireAddButtons();
    wireDeleteButtons();
    wireBulkDelete();

    hydrateContainers(root);

    // Gutenberg re-renders classic metaboxes via `dangerouslySetInnerHTML`
    // after React mounts, replacing the DOM nodes we saw at
    // DOMContentLoaded. Watch for the replacement so Sortable attaches to
    // the live container — otherwise drag-reorder / drag-nest silently
    // does nothing.
    if (typeof MutationObserver !== 'undefined' && root === document) {
        const observer = new MutationObserver(() => hydrateContainers(document));
        observer.observe(document.body, { childList: true, subtree: true });
    }
}

function hydrateContainers(root: ParentNode): void {
    const containers = root.querySelectorAll<HTMLElement>('.mua-screen-tree');
    containers.forEach((container) => {
        if (hydratedContainers.has(container)) return;
        const appId = container.dataset.appId;
        if (!appId) return;
        hydratedContainers.add(container);

        ensureA11yAttributes(container);

        wireSortables(container, () => {
            const tree = flattenTree(container);
            persistTree(appId, tree, container);
        });
    });
}

/**
 * Add ARIA landmarks that SortableJS drag doesn't provide on its own:
 *  - status element announces save outcomes to screen readers
 *  - drag handles carry a text alternative for their icon
 *  - ordered lists identify as reorderable
 *
 * Applied once per container at hydrate time; DOM contract is rendered
 * by EditorialController::renderScreenList.
 */
function ensureA11yAttributes(container: HTMLElement): void {
    const status = container.querySelector<HTMLElement>('.mua-screen-tree__status');
    if (status) {
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
    }

    container.querySelectorAll<HTMLElement>('.mua-screen-tree__handle').forEach((handle) => {
        if (!handle.hasAttribute('aria-label')) {
            handle.setAttribute('aria-label', 'Drag to reorder or nest');
        }
    });

    container.querySelectorAll<HTMLOListElement>('ol.mua-screen-tree__list').forEach((list) => {
        if (!list.hasAttribute('aria-label')) {
            list.setAttribute('aria-label', 'Screens');
        }
    });
}

function wireSortables(container: HTMLElement, onChange: () => void): void {
    const lists = container.querySelectorAll<HTMLOListElement>('ol.mua-screen-tree__list');
    lists.forEach((list) => attachSortable(list, onChange));
}

function attachSortable(list: HTMLOListElement, onChange: () => void): void {
    if (hydratedLists.has(list)) return;
    hydratedLists.add(list);

    Sortable.create(list, {
        group: 'mua-screen-tree',
        handle: '.mua-screen-tree__handle',
        animation: 150,
        fallbackOnBody: true,
        invertSwap: true,
        onEnd: () => {
            onChange();
        },
    });
}

/**
 * Walk every <li.mua-screen-tree__item> and emit a flat row carrying its
 * computed parent (from the enclosing <ol>'s data-parent-id) and its
 * sibling index (from DOM order).
 *
 * Exported so unit tests can drive it without booting SortableJS.
 */
export function flattenTree(container: ParentNode): TreeRow[] {
    const rows: TreeRow[] = [];

    const lists = container.querySelectorAll<HTMLOListElement>('ol.mua-screen-tree__list');
    lists.forEach((list) => {
        const parent = parseInt(list.dataset.parentId ?? '0', 10) || 0;
        // Walk direct children only. `:scope > li` would be cleaner but
        // jsdom's CSS engine treats it like a descendant combinator on
        // nested-list fixtures, which silently double-counts grandchildren.
        let order = 0;
        for (const child of Array.from(list.children)) {
            if (!(child instanceof HTMLElement)) continue;
            if (!child.classList.contains('mua-screen-tree__item')) continue;

            const id = parseInt(child.dataset.screenId ?? '0', 10);
            if (!id) continue;

            // Keep this item's own child <ol> labelled with the right
            // parent id — drag-into-new-parent moves the <li> but doesn't
            // touch the inner <ol>'s data attribute, so we resync it here
            // so the next flatten pass reads grandchildren under the right
            // parent.
            for (const inner of Array.from(child.children)) {
                if (
                    inner instanceof HTMLOListElement &&
                    inner.classList.contains('mua-screen-tree__list')
                ) {
                    inner.dataset.parentId = String(id);
                }
            }

            rows.push({ id, parent, order });
            order++;
        }
    });

    return rows;
}

async function persistTree(appId: string, tree: TreeRow[], container: HTMLElement): Promise<void> {
    const status = container.querySelector<HTMLElement>('.mua-screen-tree__status');
    if (status) status.textContent = 'Saving…';

    const apiFetch = resolveApiFetch();
    if (!apiFetch) {
        if (status) status.textContent = missingApiFetchMessage();
        return;
    }

    try {
        await apiFetch({
            path: `/mustuse-apps-pub/v1/apps/${appId}/screens/tree`,
            method: 'POST',
            data: { tree },
        });
        if (status) {
            status.textContent = 'Saved.';
            window.setTimeout(() => {
                if (status.textContent === 'Saved.') status.textContent = '';
            }, 1200);
        }
    } catch (err) {
        if (status) {
            status.textContent = err instanceof Error ? `Save failed: ${err.message}` : 'Save failed.';
        }
    }
}

let addHandlerBound = false;
let deleteHandlerBound = false;

function wireAddButtons(): void {
    if (addHandlerBound) return;
    addHandlerBound = true;

    document.addEventListener('click', async (e) => {
        const btn = (e.target as HTMLElement | null)?.closest<HTMLElement>('.mua-screen-tree__add');
        if (!btn) return;
        const container = btn.closest<HTMLElement>('.mua-screen-tree');
        const appId = container?.dataset.appId;
        if (!container || !appId) return;
        e.preventDefault();

        const parentId = parseInt(btn.dataset.parentId ?? '0', 10) || 0;
        const title = window.prompt('New screen title:', '');
        if (title === null) return; // cancelled

        const apiFetch = resolveApiFetch();
        if (!apiFetch) {
            window.alert(missingApiFetchMessage());
            return;
        }

        // Disable during flight so double-click doesn't create two screens.
        const wasDisabled = (btn as HTMLButtonElement).disabled;
        (btn as HTMLButtonElement).disabled = true;

        try {
            const response: { screen_id: number; edit_url: string } = await apiFetch({
                path: `/mustuse-apps-pub/v1/apps/${appId}/screens`,
                method: 'POST',
                data: { title: title.trim(), parent_id: parentId },
            });
            if (response?.edit_url) {
                window.location.href = response.edit_url;
            } else {
                window.location.reload();
            }
        } catch (err) {
            (btn as HTMLButtonElement).disabled = wasDisabled;
            const message = err instanceof Error ? err.message : 'Unknown error';
            window.alert(`Could not create screen: ${message}`);
        }
    });
}

let bulkHandlerBound = false;

function wireBulkDelete(): void {
    if (bulkHandlerBound) return;
    bulkHandlerBound = true;

    document.addEventListener('click', (e) => {
        const toggle = (e.target as HTMLElement | null)?.closest<HTMLButtonElement>('.mua-screen-tree__bulk-toggle');
        if (toggle) {
            const container = toggle.closest<HTMLElement>('.mua-screen-tree');
            if (!container) return;
            const active = container.classList.toggle('mua-screen-tree--selecting');
            container.querySelectorAll<HTMLLabelElement>('.mua-screen-tree__select').forEach((label) => {
                label.hidden = !active;
            });
            if (!active) {
                container.querySelectorAll<HTMLInputElement>('.mua-screen-tree__select-checkbox').forEach((cb) => {
                    cb.checked = false;
                });
                refreshBulkButton(container);
            }
            toggle.textContent = active ? 'Cancel select' : 'Select…';
        }
    });

    document.addEventListener('change', (e) => {
        const cb = (e.target as HTMLElement | null)?.closest<HTMLInputElement>('.mua-screen-tree__select-checkbox');
        if (!cb) return;
        const container = cb.closest<HTMLElement>('.mua-screen-tree');
        if (container) refreshBulkButton(container);
    });

    document.addEventListener('click', async (e) => {
        const btn = (e.target as HTMLElement | null)?.closest<HTMLButtonElement>('.mua-screen-tree__bulk-delete');
        if (!btn) return;
        const container = btn.closest<HTMLElement>('.mua-screen-tree');
        const appId = container?.dataset.appId;
        if (!container || !appId) return;
        e.preventDefault();

        const checked = Array.from(container.querySelectorAll<HTMLInputElement>('.mua-screen-tree__select-checkbox:checked'));
        if (checked.length === 0) return;

        const titles = checked.map((cb) => cb.dataset.screenTitle ?? '(untitled)');
        const message = `Delete ${checked.length} screen${checked.length === 1 ? '' : 's'} (and any children)?\n\n` +
            titles.slice(0, 8).join('\n') +
            (titles.length > 8 ? `\n…and ${titles.length - 8} more` : '');

        if (!window.confirm(message)) return;

        const apiFetch = resolveApiFetch();
        if (!apiFetch) {
            window.alert(missingApiFetchMessage());
            return;
        }

        const status = container.querySelector<HTMLElement>('.mua-screen-tree__status');
        btn.disabled = true;
        let done = 0;
        let failed = 0;
        for (const cb of checked) {
            const screenId = parseInt(cb.dataset.screenId ?? '0', 10) || 0;
            if (!screenId) continue;
            if (status) status.textContent = `Deleting ${done + 1}/${checked.length}…`;
            try {
                await apiFetch({
                    path: `/mustuse-apps-pub/v1/apps/${appId}/screens/${screenId}`,
                    method: 'DELETE',
                });
                cb.closest<HTMLLIElement>('li.mua-screen-tree__item')?.remove();
                done++;
            } catch {
                failed++;
            }
        }
        btn.disabled = false;
        refreshBulkButton(container);
        if (status) {
            status.textContent = failed === 0
                ? `Deleted ${done} screen${done === 1 ? '' : 's'}.`
                : `Deleted ${done}, ${failed} failed.`;
            window.setTimeout(() => {
                if (status.textContent && status.textContent.startsWith('Deleted')) status.textContent = '';
            }, 1800);
        }
    });
}

function refreshBulkButton(container: HTMLElement): void {
    const btn = container.querySelector<HTMLButtonElement>('.mua-screen-tree__bulk-delete');
    const count = container.querySelector<HTMLElement>('.mua-screen-tree__bulk-count');
    const checkedCount = container.querySelectorAll<HTMLInputElement>('.mua-screen-tree__select-checkbox:checked').length;
    if (!btn) return;
    btn.hidden = checkedCount === 0;
    if (count) count.textContent = checkedCount > 0 ? ` (${checkedCount})` : '';
}

function wireDeleteButtons(): void {
    if (deleteHandlerBound) return;
    deleteHandlerBound = true;

    document.addEventListener('click', async (e) => {
        const btn = (e.target as HTMLElement | null)?.closest<HTMLElement>('.mua-screen-tree__delete');
        if (!btn) return;
        const container = btn.closest<HTMLElement>('.mua-screen-tree');
        const appId = container?.dataset.appId;
        if (!container || !appId) return;
        e.preventDefault();

        const item = btn.closest<HTMLLIElement>('li.mua-screen-tree__item');
        const screenId = parseInt(item?.dataset.screenId ?? '0', 10) || 0;
        if (!item || !screenId) return;

        const title = btn.dataset.screenTitle ?? '(this screen)';
        const hasChildren = !!item.querySelector('li.mua-screen-tree__item');
        const message = hasChildren
            ? `Delete "${title}" AND all its child screens? This cannot be undone.`
            : `Delete "${title}"? This cannot be undone.`;

        if (!window.confirm(message)) return;

        const apiFetch = resolveApiFetch();
        if (!apiFetch) {
            window.alert(missingApiFetchMessage());
            return;
        }

        const status = container.querySelector<HTMLElement>('.mua-screen-tree__status');
        if (status) status.textContent = 'Deleting…';

        try {
            await apiFetch({
                path: `/mustuse-apps-pub/v1/apps/${appId}/screens/${screenId}`,
                method: 'DELETE',
            });
            item.remove();
            if (status) {
                status.textContent = 'Deleted.';
                window.setTimeout(() => {
                    if (status.textContent === 'Deleted.') status.textContent = '';
                }, 1200);
            }
        } catch (err) {
            if (status) status.textContent = err instanceof Error ? `Delete failed: ${err.message}` : 'Delete failed.';
        }
    });
}
