/**
 * Deployment Map — interactive 5-node pipeline on the Distribution tab.
 *
 * Subscribes to the same `pollBuildStatus` loop that the terminal uses,
 * mapping each status transition to CSS class swaps on the node elements.
 * Requirements popovers are toggled by click/keyboard on each node.
 *
 * The map is server-rendered with initial state in PHP; this module
 * adds the live-update and interaction layer.
 */

const STATUS_TO_NODES: Record<string, Record<number, string>> = {
    idle:       { 1: 'complete', 2: 'idle', 3: 'idle', 4: 'waiting', 5: 'idle' },
    pending:    { 1: 'complete', 2: 'active', 3: 'idle', 4: 'waiting', 5: 'idle' },
    projecting: { 1: 'complete', 2: 'active', 3: 'active', 4: 'waiting', 5: 'idle' },
    projected:  { 1: 'complete', 2: 'complete', 3: 'complete', 4: 'waiting', 5: 'idle' },
    unchanged:  { 1: 'complete', 2: 'complete', 3: 'complete', 4: 'waiting', 5: 'idle' },
    complete:   { 1: 'complete', 2: 'complete', 3: 'complete', 4: 'complete', 5: 'complete' },
    failed:     { 1: 'complete', 2: 'failed', 3: 'idle', 4: 'waiting', 5: 'idle' },
};

const NODE_STATES = ['idle', 'active', 'complete', 'failed', 'waiting'] as const;

/**
 * Initialise the Deployment Map. Safe to call on every page load — returns
 * immediately if the map container is absent (non-Distribution tabs, new apps).
 */
export function initDeploymentMap(root: ParentNode = document): void {
    const map = root.querySelector<HTMLElement>('#mua-dep-map');
    if (!map) return;

    // Wire node click → toggle requirements popup
    const nodes = map.querySelectorAll<HTMLElement>('.mua-dep-node');
    nodes.forEach((node) => {
        node.addEventListener('click', () => togglePopup(node, map));
        node.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                togglePopup(node, map);
            }
        });
    });

    // Close popups on outside click
    document.addEventListener('click', (e) => {
        if (!(e.target instanceof HTMLElement) || !e.target.closest('.mua-dep-node')) {
            closeAllPopups(map);
        }
    });
}

/**
 * Update the map to reflect a new build status. Called from the
 * pollBuildStatus callback in init.ts so the map and terminal stay in sync.
 */
export function updateDeploymentMap(status: string, root: ParentNode = document): void {
    const map = root.querySelector<HTMLElement>('#mua-dep-map');
    if (!map) return;

    const stateMap = STATUS_TO_NODES[status] ?? STATUS_TO_NODES['idle'];

    for (let n = 1; n <= 5; n++) {
        const node = map.querySelector<HTMLElement>(`.mua-dep-node[data-node="${n}"]`);
        if (!node) continue;

        const targetState = stateMap[n] ?? 'idle';
        applyNodeState(node, targetState);

        // Also update the connector leading INTO this node
        if (n > 1) {
            const connector = map.querySelector<HTMLElement>(
                `.mua-dep-map__connector[data-to="${n}"]`
            );
            if (connector) {
                for (const s of NODE_STATES) {
                    connector.classList.toggle(`mua-dep-map__connector--${s}`, s === targetState);
                }
            }
        }
    }
}

function applyNodeState(node: HTMLElement, state: string): void {
    for (const s of NODE_STATES) {
        node.classList.toggle(`mua-dep-node--${s}`, s === state);
    }
}

function togglePopup(node: HTMLElement, map: HTMLElement): void {
    const popup = node.querySelector<HTMLElement>('.mua-dep-node__popup');
    const isOpen = popup ? !popup.hidden : false;

    closeAllPopups(map);

    if (popup && !isOpen) {
        popup.hidden = false;
    }
}

function closeAllPopups(map: HTMLElement): void {
    map.querySelectorAll<HTMLElement>('.mua-dep-node__popup').forEach((p) => {
        p.hidden = true;
    });
}
