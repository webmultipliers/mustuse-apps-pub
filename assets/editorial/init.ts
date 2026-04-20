/**
 * Pure init functions for the Editorial admin surface.
 *
 * Extracted out of main.ts so Vitest can exercise them against jsdom
 * fixtures without running the entry's `DOMContentLoaded` wiring.
 *
 * Entry-point side effects (wiring them to DOMContentLoaded) live in
 * main.ts. The functions here take an optional `ParentNode` root so
 * tests can scope queries to a test-constructed fragment.
 */

import { updateDeploymentMap } from './deployment-map';

/**
 * Wire the "Test Connection" button on the Distribution tab to the
 * per-app `/test-github-connection` endpoint.
 */
export function initTestConnection(root: ParentNode = document): void {
    const btn = root.querySelector<HTMLButtonElement>('#mua-test-connection');
    if (!btn) return;

    const cfg = window.MUA_EDITORIAL;
    const apiFetch = window.wp?.apiFetch;
    if (!cfg || !apiFetch) return;

    const resultSpan = root.querySelector<HTMLElement>('#mua-test-result');
    const repoInput = root.querySelector<HTMLInputElement>('#build_repo_url');
    const tokenInput = root.querySelector<HTMLInputElement>('#github_token');
    const appId = btn.dataset.appId ?? '';

    btn.addEventListener('click', async () => {
        if (!resultSpan) return;
        resultSpan.style.display = 'inline';
        resultSpan.style.color = '#555';
        resultSpan.textContent = 'Testing\u2026';
        btn.disabled = true;

        try {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const response: any = await apiFetch({
                path: '/mustuse-apps-pub/v1/test-github-connection',
                method: 'POST',
                data: {
                    app_id: appId ? parseInt(appId, 10) : 0,
                    repo_url: repoInput?.value.trim() ?? '',
                    token: tokenInput?.value ?? '',
                },
            });

            if (response?.ok) {
                resultSpan.style.color = '#1a7f37';
                resultSpan.textContent = `Connected \u2014 ${response.owner}/${response.repo} (${response.default_branch})`;
            } else {
                resultSpan.style.color = '#b0264c';
                resultSpan.textContent = response?.error ?? 'Connection failed.';
            }
        } catch (err) {
            resultSpan.style.color = '#b0264c';
            resultSpan.textContent = err instanceof Error ? err.message : String(err);
        } finally {
            btn.disabled = false;
        }
    });
}

/**
 * Localized REST config injected by EditorialController via
 * `wp_localize_script`. Defined here so the module is the single source
 * of truth for the window augmentations used by initShipIt.
 */
declare global {
    interface Window {
        MUA_EDITORIAL?: { restBase: string; nonce: string };
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        wp?: { apiFetch?: any };
    }
}

/** Tab switching for the App Editor and any tabbed interface. */
export function initEditorialTabs(root: ParentNode = document): void {
    root.querySelectorAll<HTMLElement>('.mua-tab-bar').forEach((bar) => {
        const tabs = bar.querySelectorAll<HTMLButtonElement>('.mua-tab');

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const targetId = tab.dataset.tabTarget;
                if (!targetId) return;

                tabs.forEach((t) => t.classList.remove('is-active'));
                bar.parentElement
                    ?.querySelectorAll<HTMLElement>('.mua-tab-panel')
                    .forEach((p) => p.classList.remove('is-active'));

                tab.classList.add('is-active');
                document.getElementById(targetId)?.classList.add('is-active');

                const tabInput = document.getElementById('mua-active-tab') as HTMLInputElement | null;
                if (tabInput && targetId.startsWith('tab-')) {
                    tabInput.value = targetId.replace('tab-', '');
                }
            });
        });
    });
}

/**
 * Snapshot of `window._` captured at module init.
 *
 * WP's `wp.media(...)` calls `_.defaults()` internally. On sites where a
 * theme/plugin loads lodash as a plain `<script>` (no handle) that lands
 * *after* our bundle, `window._` gets overwritten with something that
 * lacks `_.defaults`, and every click on "Choose Image" throws
 * `_.defaults is not a function` inside `media-models.min.js`.
 *
 * Calling this at module-eval time — before the offender's script has had
 * a chance to run — lets the click handler put genuine Underscore back on
 * `window._` just before invoking `wp.media()`.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let stashedUnderscore: any = null;

export function stashUnderscore(): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const current = (window as any)._;
    if (current && typeof current.defaults === 'function') {
        stashedUnderscore = current;
    }
}

/**
 * WordPress media library integration for image fields.
 *
 * Uses click delegation on `document` rather than binding per-button:
 * Gutenberg re-inserts classic metaboxes via `dangerouslySetInnerHTML`
 * after React mounts, which would drop any listeners bound at
 * DOMContentLoaded. Delegation survives the re-insert.
 */
export function initMediaUploads(root: Document | HTMLElement = document): void {
    const host: Document | HTMLElement = root;

    host.addEventListener('click', (event) => {
        const target = event.target as HTMLElement | null;
        const uploadBtn = target?.closest<HTMLButtonElement>('.mua-media-upload-btn');
        if (uploadBtn) {
            const field = uploadBtn.closest<HTMLElement>('.mua-media-field');
            const targetId = field?.dataset.target;
            if (!field || !targetId) return;

            const input = document.getElementById(targetId) as HTMLInputElement | null;
            if (!input) return;

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const wpGlobal = (window as any).wp;
            if (!wpGlobal?.media) {
                // eslint-disable-next-line no-console
                console.error('[mua] wp.media unavailable — wp_enqueue_media() may not have run on this screen.');
                return;
            }

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const currentUnderscore = (window as any)._;
            const underscoreBroken = !currentUnderscore || typeof currentUnderscore.defaults !== 'function';
            if (underscoreBroken && stashedUnderscore) {
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                (window as any)._ = stashedUnderscore;
            } else if (underscoreBroken) {
                // eslint-disable-next-line no-console
                console.error('[mua] window._ is not Underscore and no stash is available — the WP media modal cannot open.');
                return;
            }

            const frame = wpGlobal.media({
                title: 'Choose Image',
                multiple: false,
                library: { type: 'image' },
            });

            frame.on('select', () => {
                const attachment = frame.state().get('selection').first().toJSON();
                input.value = attachment.url;

                let preview = field.querySelector<HTMLImageElement>('.mua-media-preview');
                if (!preview) {
                    preview = document.createElement('img');
                    preview.className = 'mua-media-preview';
                    preview.alt = '';
                    field.insertBefore(preview, uploadBtn);
                }
                preview.src = attachment.url;

                const removeBtn = field.querySelector<HTMLButtonElement>('.mua-media-remove-btn');
                if (removeBtn) removeBtn.style.display = '';
            });

            frame.open();
            return;
        }

        const removeBtn = target?.closest<HTMLButtonElement>('.mua-media-remove-btn');
        if (removeBtn) {
            const field = removeBtn.closest<HTMLElement>('.mua-media-field');
            const targetId = field?.dataset.target;
            if (!field || !targetId) return;

            const input = document.getElementById(targetId) as HTMLInputElement | null;
            if (!input) return;

            input.value = '';
            field.querySelector<HTMLImageElement>('.mua-media-preview')?.remove();
            removeBtn.style.display = 'none';
        }
    });
}

/** Live-sync branding color pickers to the mobile preview pane. */
export function initBrandingPreview(root: ParentNode = document): void {
    const header = root.querySelector<HTMLElement>('#mua-preview-header');
    if (!header) return;

    const accentInput = root.querySelector<HTMLInputElement>('#branding_accent_color');
    const bgInput = root.querySelector<HTMLInputElement>('#branding_background_color');
    const previewBody = header.parentElement?.querySelector<HTMLElement>('.mua-preview-body');

    accentInput?.addEventListener('input', () => {
        header.style.background = accentInput.value;
    });

    bgInput?.addEventListener('input', () => {
        if (previewBody) previewBody.style.background = bgInput.value;
    });
}

/**
 * Wire the "Ship It!" button to ShipRoute.
 *
 * We don't have a streaming build-log endpoint yet, so the "terminal" only
 * carries the request lifecycle (queued / failed) plus whatever the route
 * returns. Future work: poll `_mua_last_build_status` or move to SSE for
 * real progress lines.
 */
export function initShipIt(root: ParentNode = document): void {
    const btn = root.querySelector<HTMLButtonElement>('#mua-ship-it');
    const terminal = root.querySelector<HTMLElement>('#mua-build-terminal');
    if (!btn || !terminal) return;

    const cfg = window.MUA_EDITORIAL;
    const apiFetch = window.wp?.apiFetch;
    if (!cfg || !apiFetch) {
        // Localized config or wp.apiFetch missing; leave the button as-is so
        // the failure is visible rather than silently dispatching to nowhere.
        return;
    }

    const log = (line: string, kind: 'info' | 'error' | 'success' = 'info'): void => {
        terminal.hidden = false;
        terminal.classList.add('is-active');
        const row = document.createElement('div');
        row.className = `log-${kind}`;
        const ts = new Date().toISOString().replace('T', ' ').slice(0, 19);
        row.textContent = `[${ts}] ${line}`;
        terminal.appendChild(row);
        terminal.scrollTop = terminal.scrollHeight;
    };

    btn.addEventListener('click', async () => {
        const appId = btn.dataset.appId;
        const target = btn.dataset.shipTarget || 'all';
        if (!appId) return;

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Dispatching…';
        log(`Submitting build request for app ${appId} (target: ${target})…`);

        try {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const response: any = await apiFetch({
                path: `/mustuse-apps-pub/v1/apps/${appId}/ship`,
                method: 'POST',
                data: { target },
            });

            const status = response?.status ?? 'queued';
            const branch = response?.branch ?? '';
            log(`Build ${status}${branch ? ` on branch ${branch}` : ''}.`, 'success');

            // Reveal the Next Steps panel and render env rows the publisher
            // needs to paste into Bifrost. Compare URL is filled in later by
            // pollBuildStatus when the projection job finishes.
            const envRows = Array.isArray(response?.env_for_bifrost) ? response.env_for_bifrost : [];
            renderNextStepsPanel(envRows, root);

            btn.disabled = false;
            btn.textContent = originalLabel;

            pollBuildStatus(appId, log, apiFetch).catch(() => {});
        } catch (err) {
            const message = err instanceof Error ? err.message : String(err);
            log(`Failed to dispatch build: ${message}`, 'error');
            btn.disabled = false;
            btn.textContent = originalLabel;
        }
    });
}

export interface EnvRowForBifrost {
    name: string;
    value: string;
    secret: boolean;
}

export interface ShipHistoryEntry {
    ts: string;
    status: string;
    branch: string;
    commit_sha: string;
    compare_url: string;
    files_committed: number;
    error: string;
    duration_ms: number;
}

/**
 * Render the ship history table from a list of entries (newest first).
 * Called by pollBuildStatus on every terminal response so publishers see
 * their full ship log live, without a page refresh.
 */
export function renderShipHistory(entries: ShipHistoryEntry[], root: ParentNode = document): void {
    const tbody = root.querySelector<HTMLElement>('#mua-ship-history tbody');
    if (!tbody) return;

    tbody.replaceChildren();

    if (entries.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 5;
        td.className = 'mua-text-muted';
        td.textContent = 'No ship history yet.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
    }

    for (const e of entries) {
        tbody.appendChild(buildHistoryRow(e));
    }
}

function buildHistoryRow(e: ShipHistoryEntry): HTMLTableRowElement {
    const tr = document.createElement('tr');

    const tsCell = document.createElement('td');
    tsCell.textContent = e.ts;
    tr.appendChild(tsCell);

    const statusCell = document.createElement('td');
    const badge = document.createElement('span');
    badge.className = `mua-badge ${historyBadgeClass(e.status)}`;
    badge.textContent = e.status;
    statusCell.appendChild(badge);
    tr.appendChild(statusCell);

    const branchCell = document.createElement('td');
    if (e.branch) {
        const code = document.createElement('code');
        code.textContent = e.branch;
        branchCell.appendChild(code);
    } else {
        branchCell.textContent = '—';
    }
    tr.appendChild(branchCell);

    const outcomeCell = document.createElement('td');
    if (e.compare_url) {
        const link = document.createElement('a');
        link.href = e.compare_url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = `${e.files_committed || 0} file(s) →`;
        outcomeCell.appendChild(link);
    } else if (e.error) {
        outcomeCell.textContent = e.error;
        outcomeCell.className = 'mua-text-error';
    } else {
        outcomeCell.textContent = '—';
    }
    tr.appendChild(outcomeCell);

    const durCell = document.createElement('td');
    durCell.textContent = `${e.duration_ms}ms`;
    tr.appendChild(durCell);

    return tr;
}

function historyBadgeClass(status: string): string {
    switch (status) {
        case 'projected': return 'mua-badge--success';
        case 'unchanged': return 'mua-badge--neutral';
        case 'failed':    return 'mua-badge--danger';
        default:          return 'mua-badge--warning';
    }
}

/**
 * Reveals the Next Steps panel and populates the env-vars table.
 * Idempotent — re-rendering wipes and rebuilds the tbody so a publisher
 * can ship multiple times without stale rows accumulating.
 */
export function renderNextStepsPanel(rows: EnvRowForBifrost[], root: ParentNode = document): void {
    const panel = root.querySelector<HTMLElement>('#mua-ship-success');
    const table = root.querySelector<HTMLTableElement>('#mua-env-table');
    if (!panel || !table) return;

    panel.hidden = false;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    tbody.replaceChildren();

    for (const row of rows) {
        tbody.appendChild(buildEnvRow(row));
    }

    // Wire the "Download as .env" button — generates a KEY=value blob.
    const dlBtn = root.querySelector<HTMLButtonElement>('#mua-download-env');
    if (dlBtn && rows.length > 0) {
        dlBtn.hidden = false;
        dlBtn.onclick = () => downloadEnvFile(rows);
    }

    // Reset the compare-link state on every render so a re-ship doesn't
    // leave the old branch URL active before the new poll finishes.
    const link = root.querySelector<HTMLAnchorElement>('#mua-compare-link');
    const pending = root.querySelector<HTMLElement>('#mua-compare-pending');
    if (link) {
        link.hidden = true;
        link.removeAttribute('href');
    }
    if (pending) {
        pending.hidden = false;
    }
}

/**
 * Surface the compare URL CTA once the projection job has produced a branch.
 * Hides the "(branch link appears once projection completes)" placeholder.
 */
export function revealCompareLink(url: string, root: ParentNode = document): void {
    const link = root.querySelector<HTMLAnchorElement>('#mua-compare-link');
    const pending = root.querySelector<HTMLElement>('#mua-compare-pending');
    if (link) {
        link.href = url;
        link.hidden = false;
    }
    if (pending) {
        pending.hidden = true;
    }
}

function buildEnvRow(row: EnvRowForBifrost): HTMLTableRowElement {
    const tr = document.createElement('tr');
    tr.dataset.secret = row.secret ? 'true' : 'false';

    const nameCell = document.createElement('td');
    const nameCode = document.createElement('code');
    nameCode.textContent = row.name;
    nameCell.appendChild(nameCode);

    const valueCell = document.createElement('td');
    valueCell.className = 'mua-env-table__value';
    const valueCode = document.createElement('code');
    valueCode.className = 'mua-env-table__display';
    valueCode.textContent = row.secret ? maskSecret(row.value) : row.value;
    valueCell.appendChild(valueCode);

    const actionsCell = document.createElement('td');
    actionsCell.className = 'mua-env-table__actions';

    if (row.secret) {
        const reveal = document.createElement('button');
        reveal.type = 'button';
        reveal.className = 'mua-env-table__btn';
        reveal.dataset.action = 'reveal';
        reveal.textContent = 'Reveal';
        reveal.addEventListener('click', () => {
            const showing = reveal.dataset.shown === 'true';
            valueCode.textContent = showing ? maskSecret(row.value) : row.value;
            reveal.textContent = showing ? 'Reveal' : 'Hide';
            reveal.dataset.shown = showing ? 'false' : 'true';
        });
        actionsCell.appendChild(reveal);
    }

    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'mua-env-table__btn';
    copy.dataset.action = 'copy';
    copy.textContent = 'Copy';
    copy.addEventListener('click', () => {
        copyToClipboard(row.value).then((ok) => {
            if (!ok) return;
            const original = copy.textContent;
            copy.textContent = 'Copied';
            copy.classList.add('is-copied');
            setTimeout(() => {
                copy.textContent = original;
                copy.classList.remove('is-copied');
            }, 1500);
        });
    });
    actionsCell.appendChild(copy);

    tr.appendChild(nameCell);
    tr.appendChild(valueCell);
    tr.appendChild(actionsCell);
    return tr;
}

function maskSecret(value: string): string {
    if (value === '') return '(empty)';
    return '•'.repeat(Math.min(value.length, 12));
}

function downloadEnvFile(rows: EnvRowForBifrost[]): void {
    const content = rows.map((r) => `${r.name}=${r.value}`).join('\n') + '\n';
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'bifrost.env';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

async function copyToClipboard(text: string): Promise<boolean> {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch {
        // fall through to legacy path
    }

    // Fallback for environments without the async clipboard API.
    try {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(textarea);
        return ok;
    } catch {
        return false;
    }
}

/**
 * On page load, if a build is still in flight (pending / projecting),
 * poll the build-status endpoint and update the metabox in place when a
 * terminal state arrives. Quiet polling — no terminal log noise; just
 * the metabox status text.
 */
export function initBuildStatusPoller(root: ParentNode = document): void {
    const block = root.querySelector<HTMLElement>('#mua-build-status');
    if (!block) return;

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const apiFetch = (window as any).wp?.apiFetch;
    if (!apiFetch) return;

    const inFlight = new Set(['pending', 'queued', 'projecting']);
    const initial = (block.dataset.status ?? '').toLowerCase();
    if (!inFlight.has(initial)) return;

    const appId = block.dataset.appId;
    if (!appId) return;

    const update = (status: string, when?: string, error?: string): void => {
        block.dataset.status = status;
        const labelEl = block.querySelector<HTMLElement>('.mua-build-status__label');
        const whenEl = block.querySelector<HTMLElement>('.mua-build-status__when');
        const errorEl = block.querySelector<HTMLElement>('.mua-build-status__error');
        if (labelEl) labelEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        if (whenEl && when) whenEl.textContent = ' — ' + when;
        if (errorEl) {
            if (error) {
                const para = errorEl.querySelector('p');
                if (para) para.textContent = error;
                errorEl.hidden = false;
            } else {
                errorEl.hidden = true;
            }
        }
    };

    const intervalMs = 3000;
    const maxPolls = 40;
    let polls = 0;

    const tick = async (): Promise<void> => {
        polls++;
        try {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const r: any = await apiFetch({
                path: `/mustuse-apps-pub/v1/apps/${appId}/build-status`,
                method: 'GET',
            });
            const status = (r?.status ?? 'unknown').toLowerCase();
            update(status, r?.completed_at, r?.error);
            if (r?.terminal || polls >= maxPolls) return;
        } catch {
            return;
        }
        setTimeout(() => { void tick(); }, intervalMs);
    };

    setTimeout(() => { void tick(); }, intervalMs);
}

/**
 * Poll the build-status endpoint until a terminal state is reached. Caps
 * at 40 polls (≈2 minutes at 3s) so a stalled projection job can't keep
 * the tab busy forever — the user can manually refresh after that.
 *
 * Exposed so tests can drive the polling loop directly with a mocked
 * apiFetch and observe the log output.
 */
export async function pollBuildStatus(
    appId: string,
    log: (line: string, kind?: 'info' | 'error' | 'success') => void,
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    apiFetch: any,
    options: { intervalMs?: number; maxPolls?: number } = {}
): Promise<void> {
    const intervalMs = options.intervalMs ?? 3000;
    const maxPolls = options.maxPolls ?? 40;
    let lastStatus = '';

    for (let i = 0; i < maxPolls; i++) {
        await new Promise((r) => setTimeout(r, intervalMs));

        let response: {
            status?: string;
            terminal?: boolean;
            version?: string;
            error?: string;
            compare_url?: string;
            branch?: string;
            history?: ShipHistoryEntry[];
        } = {};
        try {
            response = await apiFetch({
                path: `/mustuse-apps-pub/v1/apps/${appId}/build-status`,
                method: 'GET',
            });
        } catch (err) {
            const message = err instanceof Error ? err.message : String(err);
            log(`Build-status poll failed: ${message}`, 'error');
            return;
        }

        const status = response.status ?? 'unknown';
        if (status !== lastStatus) {
            log(`Build status: ${status}${response.version ? ` (version ${response.version})` : ''}`);
            lastStatus = status;
            updateDeploymentMap(status);
        }

        if (response.terminal) {
            if (status === 'projected') {
                log(`Projected to GitHub${response.branch ? ` (branch ${response.branch})` : ''}.`, 'success');
                if (response.compare_url) {
                    revealCompareLink(response.compare_url);
                }
            } else if (status === 'unchanged') {
                log(
                    `No changes since last ship${response.branch ? ` (still on ${response.branch})` : ''} — skipped GitHub push.`,
                    'info',
                );
                if (response.compare_url) {
                    revealCompareLink(response.compare_url);
                }
            } else if (status === 'complete') {
                log(`Build complete${response.version ? ` — version ${response.version}` : ''}.`, 'success');
            } else if (status === 'failed') {
                log(`Build failed${response.error ? `: ${response.error}` : '.'}`, 'error');
            }
            if (Array.isArray(response.history)) {
                renderShipHistory(response.history);
            }
            return;
        }
    }

    log('Stopped polling after 2 minutes without a terminal build status. Refresh the page to check manually.', 'error');
}

