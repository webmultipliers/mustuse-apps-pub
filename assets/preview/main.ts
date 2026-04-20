/**
 * Preview dashboard runtime.
 *
 * Tiny by design — most of the dashboard is server-rendered by
 * views/preview/dashboard.php. The TS layer only handles:
 *  - Splash overlay timeout dismiss + manual skip button
 *  - Bottom-nav tab switching when previewing an app (screen panes are
 *    pre-rendered into the device frame and shown/hidden by data-active)
 *
 * No REST calls. No live polling. No state beyond DOM `data-` attrs.
 */

const SPLASH_AUTODISMISS_MS = 1500;

function onReady(fn: () => void): void {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
    } else {
        fn();
    }
}

export function initSplash(root: ParentNode = document): void {
    const splash = root.querySelector<HTMLElement>('[data-mua-splash]');
    if (!splash) return;

    const dismiss = (): void => {
        if (splash.classList.contains('is-hidden')) return;
        splash.classList.add('is-hidden');
        // Match the CSS transition duration before yanking from the layout
        // tree, so click events on the dashboard underneath aren't swallowed
        // by a still-painting overlay.
        window.setTimeout(() => {
            splash.style.display = 'none';
        }, 600);
    };

    splash.querySelector<HTMLButtonElement>('[data-mua-splash-skip]')?.addEventListener('click', dismiss);
    window.setTimeout(dismiss, SPLASH_AUTODISMISS_MS);
}

export function initScreenSwitcher(root: ParentNode = document): void {
    const frame = root.querySelector<HTMLElement>('[data-mua-frame]');
    if (!frame) return;

    const tabs = frame.querySelectorAll<HTMLButtonElement>('[data-mua-nav-tab]');
    if (tabs.length === 0) return;

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const targetId = tab.dataset.muaNavTab;
            if (!targetId) return;
            activate(frame, targetId);
        });
    });
}

/**
 * Show the pane matching `targetId`, hide siblings, mirror the active
 * state into the bottom-nav tabs. Exported for unit testing.
 */
export function activate(frame: ParentNode, targetId: string): void {
    frame.querySelectorAll<HTMLElement>('[data-mua-screen-pane]').forEach((pane) => {
        const isMatch = pane.dataset.muaScreenPane === targetId;
        pane.toggleAttribute('hidden', !isMatch);
    });
    frame.querySelectorAll<HTMLElement>('[data-mua-nav-tab]').forEach((tab) => {
        tab.classList.toggle('is-active', tab.dataset.muaNavTab === targetId);
    });
}

onReady(() => {
    initSplash();
    initScreenSwitcher();
});
