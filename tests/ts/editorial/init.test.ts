import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    initBrandingPreview,
    initMediaUploads,
    initShipIt,
    pollBuildStatus,
    renderNextStepsPanel,
    revealCompareLink,
    stashUnderscore,
} from '@editorial/init';

function nextStepsFixture(): void {
    document.body.innerHTML = `
        <div id="mua-ship-success" hidden>
            <a id="mua-compare-link" href="#" hidden></a>
            <span id="mua-compare-pending"></span>
            <table id="mua-env-table"><tbody></tbody></table>
        </div>
    `;
}

beforeEach(() => {
    document.body.innerHTML = '';
    // Clear any leftover window state from prior tests.
    delete (window as Window).MUA_EDITORIAL;
    delete (window as Window).wp;
});

describe('initBrandingPreview', () => {
    it('syncs accent color to the preview header background', () => {
        document.body.innerHTML = `
            <div>
                <div id="mua-preview-header"></div>
                <div class="mua-preview-body"></div>
            </div>
            <input type="color" id="branding_accent_color" value="#ff0000">
            <input type="color" id="branding_background_color" value="#00ff00">
        `;
        initBrandingPreview();

        const accent = document.getElementById('branding_accent_color') as HTMLInputElement;
        accent.value = '#123456';
        accent.dispatchEvent(new Event('input'));

        expect(document.getElementById('mua-preview-header')!.style.background).toMatch(/#123456|rgb\(18, 52, 86\)/);
    });

    it('exits cleanly when the preview header is missing', () => {
        document.body.innerHTML = '<p>No preview here</p>';
        expect(() => initBrandingPreview()).not.toThrow();
    });
});

describe('initShipIt', () => {
    function shipFixture(): HTMLButtonElement {
        document.body.innerHTML = `
            <button id="mua-ship-it" data-app-id="42" data-ship-target="all">Ship It!</button>
            <pre id="mua-build-terminal" hidden></pre>
        `;
        return document.getElementById('mua-ship-it') as HTMLButtonElement;
    }

    it('is a no-op when MUA_EDITORIAL config is missing', () => {
        shipFixture();
        // No window.MUA_EDITORIAL set.
        expect(() => initShipIt()).not.toThrow();

        // Clicking shouldn't throw either; the handler wasn't attached.
        document.getElementById('mua-ship-it')!.click();
        expect(document.getElementById('mua-build-terminal')!.hidden).toBe(true);
    });

    it('POSTs to ShipRoute and logs a success line', async () => {
        const apiFetch = vi.fn().mockResolvedValue({ status: 'queued', app_id: 42, target: 'all' });
        (window as Window).MUA_EDITORIAL = { restBase: 'https://pub.test/wp-json/mustuse-apps-pub/v1', nonce: 'n' };
        (window as Window).wp = { apiFetch };

        const btn = shipFixture();
        initShipIt();
        btn.click();

        // Wait for the async handler to settle.
        await new Promise((r) => setTimeout(r, 0));
        await new Promise((r) => setTimeout(r, 0));

        expect(apiFetch).toHaveBeenCalledWith({
            path: '/mustuse-apps-pub/v1/apps/42/ship',
            method: 'POST',
            data: { target: 'all' },
        });
        const terminal = document.getElementById('mua-build-terminal')!;
        expect(terminal.hidden).toBe(false);
        expect(terminal.textContent).toMatch(/queued/);
    });

    it('logs the error and restores the button on failure', async () => {
        const apiFetch = vi.fn().mockRejectedValue(new Error('boom'));
        (window as Window).MUA_EDITORIAL = { restBase: 'x', nonce: 'n' };
        (window as Window).wp = { apiFetch };

        const btn = shipFixture();
        initShipIt();
        btn.click();

        await new Promise((r) => setTimeout(r, 0));
        await new Promise((r) => setTimeout(r, 0));

        expect(btn.disabled).toBe(false);
        expect(btn.textContent).toBe('Ship It!');
        expect(document.getElementById('mua-build-terminal')!.textContent).toMatch(/boom/);
    });
});

describe('pollBuildStatus', () => {
    it('stops on a terminal `complete` response and logs version', async () => {
        const apiFetch = vi.fn()
            .mockResolvedValueOnce({ status: 'queued', terminal: false })
            .mockResolvedValueOnce({ status: 'running', terminal: false })
            .mockResolvedValueOnce({ status: 'complete', terminal: true, version: '1.2.3' });

        const lines: string[] = [];
        const log = (line: string) => lines.push(line);

        await pollBuildStatus('42', log, apiFetch, { intervalMs: 0, maxPolls: 10 });

        // Only 3 polls before terminal — the 4th shouldn't fire.
        expect(apiFetch).toHaveBeenCalledTimes(3);
        expect(lines.join('\n')).toMatch(/complete.*1\.2\.3/);
    });

    it('stops on `failed` and surfaces the error', async () => {
        const apiFetch = vi.fn().mockResolvedValueOnce({
            status: 'failed',
            terminal: true,
            error: 'signing failed',
        });

        const lines: string[] = [];
        const log = (line: string, _kind?: string) => lines.push(line);

        await pollBuildStatus('42', log, apiFetch, { intervalMs: 0 });

        expect(apiFetch).toHaveBeenCalledTimes(1);
        expect(lines.join('\n')).toMatch(/Build failed.*signing failed/);
    });

    it('deduplicates consecutive same-status log lines', async () => {
        const apiFetch = vi.fn()
            .mockResolvedValueOnce({ status: 'queued', terminal: false })
            .mockResolvedValueOnce({ status: 'queued', terminal: false })
            .mockResolvedValueOnce({ status: 'complete', terminal: true });

        const lines: string[] = [];
        const log = (line: string) => lines.push(line);

        await pollBuildStatus('42', log, apiFetch, { intervalMs: 0, maxPolls: 5 });

        const queuedLines = lines.filter((l) => l.startsWith('Build status: queued'));
        expect(queuedLines).toHaveLength(1);
    });

    it('gives up after maxPolls non-terminal responses', async () => {
        const apiFetch = vi.fn().mockResolvedValue({ status: 'running', terminal: false });

        const lines: string[] = [];
        const log = (line: string) => lines.push(line);

        await pollBuildStatus('42', log, apiFetch, { intervalMs: 0, maxPolls: 3 });

        expect(apiFetch).toHaveBeenCalledTimes(3);
        expect(lines[lines.length - 1]).toMatch(/Stopped polling/);
    });

    it('aborts + logs on apiFetch rejection', async () => {
        const apiFetch = vi.fn().mockRejectedValue(new Error('network'));

        const lines: string[] = [];
        const log = (line: string) => lines.push(line);

        await pollBuildStatus('42', log, apiFetch, { intervalMs: 0, maxPolls: 10 });

        expect(apiFetch).toHaveBeenCalledTimes(1);
        expect(lines[0]).toMatch(/poll failed.*network/);
    });
});

describe('renderNextStepsPanel', () => {
    it('reveals the panel and renders one row per env var', () => {
        nextStepsFixture();

        renderNextStepsPanel([
            { name: 'NATIVEPHP_APP_ID', value: 'com.mustuse.foo', secret: false },
            { name: 'MUA_APPKEY',       value: 'super-secret-key', secret: true  },
        ]);

        const panel = document.getElementById('mua-ship-success') as HTMLElement;
        expect(panel.hidden).toBe(false);

        const rows = panel.querySelectorAll('tbody tr');
        expect(rows).toHaveLength(2);

        const publicRow = rows[0];
        expect(publicRow.getAttribute('data-secret')).toBe('false');
        expect(publicRow.textContent).toContain('NATIVEPHP_APP_ID');
        expect(publicRow.textContent).toContain('com.mustuse.foo');
        expect(publicRow.querySelector('[data-action="reveal"]')).toBeNull();

        const secretRow = rows[1];
        expect(secretRow.getAttribute('data-secret')).toBe('true');
        expect(secretRow.textContent).toContain('MUA_APPKEY');
        expect(secretRow.textContent).not.toContain('super-secret-key');
        expect(secretRow.textContent).toMatch(/•+/);
        expect(secretRow.querySelector('[data-action="reveal"]')).not.toBeNull();
    });

    it('toggles a secret row between masked and revealed', () => {
        nextStepsFixture();

        renderNextStepsPanel([
            { name: 'API_TOKEN', value: 'abc123', secret: true },
        ]);

        const row = document.querySelector('tbody tr')!;
        const display = row.querySelector('.mua-env-table__display') as HTMLElement;
        const reveal = row.querySelector('[data-action="reveal"]') as HTMLButtonElement;

        expect(display.textContent).not.toBe('abc123');

        reveal.click();
        expect(display.textContent).toBe('abc123');
        expect(reveal.textContent).toBe('Hide');

        reveal.click();
        expect(display.textContent).not.toBe('abc123');
        expect(reveal.textContent).toBe('Reveal');
    });

    it('replaces existing rows on re-render so a re-ship does not accumulate stale rows', () => {
        nextStepsFixture();

        renderNextStepsPanel([
            { name: 'A', value: '1', secret: false },
            { name: 'B', value: '2', secret: false },
        ]);
        expect(document.querySelectorAll('tbody tr')).toHaveLength(2);

        renderNextStepsPanel([
            { name: 'C', value: '3', secret: false },
        ]);
        const rows = document.querySelectorAll('tbody tr');
        expect(rows).toHaveLength(1);
        expect(rows[0].textContent).toContain('C');
        expect(rows[0].textContent).not.toContain('A');
    });

    it('resets the compare-link state on re-render so the old branch is not still linked', () => {
        nextStepsFixture();
        const link = document.getElementById('mua-compare-link') as HTMLAnchorElement;
        const pending = document.getElementById('mua-compare-pending') as HTMLElement;

        // Simulate a prior projection having filled in the link.
        link.hidden = false;
        link.href = 'https://github.com/old/repo/compare/main...build-old';
        pending.hidden = true;

        renderNextStepsPanel([{ name: 'X', value: '1', secret: false }]);

        expect(link.hidden).toBe(true);
        expect(link.hasAttribute('href')).toBe(false);
        expect(pending.hidden).toBe(false);
    });

    it('copies the underlying value (not the masked display) to the clipboard', async () => {
        nextStepsFixture();
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });

        renderNextStepsPanel([
            { name: 'API_TOKEN', value: 'abc123', secret: true },
        ]);

        const copyBtn = document.querySelector('[data-action="copy"]') as HTMLButtonElement;
        copyBtn.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(writeText).toHaveBeenCalledWith('abc123');
    });
});

describe('revealCompareLink', () => {
    it('sets the href, shows the link, and hides the pending placeholder', () => {
        nextStepsFixture();

        revealCompareLink('https://github.com/owner/repo/compare/main...build-x');

        const link = document.getElementById('mua-compare-link') as HTMLAnchorElement;
        const pending = document.getElementById('mua-compare-pending') as HTMLElement;

        expect(link.hidden).toBe(false);
        expect(link.getAttribute('href')).toBe('https://github.com/owner/repo/compare/main...build-x');
        expect(pending.hidden).toBe(true);
    });
});

describe('initMediaUploads', () => {
    function renderBrandingField(): void {
        document.body.innerHTML = `
            <table><tbody><tr>
                <td class="mua-media-field" data-target="mua_branding_icon_url">
                    <input type="hidden" id="mua_branding_icon_url" name="mua_branding_icon_url" value="">
                    <button type="button" class="button mua-media-upload-btn">Choose Image</button>
                    <button type="button" class="button mua-media-remove-btn" style="display:none">Remove</button>
                </td>
            </tr></tbody></table>
        `;
    }

    function stubWpMedia(attachmentUrl: string): { frame: { on: ReturnType<typeof vi.fn>; open: ReturnType<typeof vi.fn> }; fire: () => void } {
        const handlers: Record<string, () => void> = {};
        const frame = {
            on: vi.fn((event: string, cb: () => void) => { handlers[event] = cb; }),
            open: vi.fn(),
            state: () => ({ get: () => ({ first: () => ({ toJSON: () => ({ url: attachmentUrl }) }) }) }),
        };
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (window as any).wp = { media: vi.fn(() => frame) };
        // Stand in for Underscore — the click handler asserts `_.defaults`
        // exists before invoking wp.media() to avoid the real-site crash.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (window as any)._ = { defaults: vi.fn() };
        return { frame, fire: () => handlers.select?.() };
    }

    it('opens the media modal and stores the selected URL in the hidden input', () => {
        renderBrandingField();
        initMediaUploads();
        const { frame, fire } = stubWpMedia('https://example.com/icon.png');

        document.querySelector<HTMLButtonElement>('.mua-media-upload-btn')!.click();
        expect(frame.open).toHaveBeenCalled();

        fire();

        const hidden = document.getElementById('mua_branding_icon_url') as HTMLInputElement;
        expect(hidden.value).toBe('https://example.com/icon.png');
        const removeBtn = document.querySelector<HTMLButtonElement>('.mua-media-remove-btn')!;
        expect(removeBtn.style.display).toBe('');
    });

    it('survives a Gutenberg-style metabox re-insert (dangerouslySetInnerHTML)', () => {
        // Initialize BEFORE the field exists — as happens in real life when
        // the editorial bundle runs at DOMContentLoaded but React hasn't
        // yet rewritten the metaboxes container.
        document.body.innerHTML = '<div id="metaboxes"></div>';
        initMediaUploads();

        // Now simulate React replacing the metaboxes container contents
        // via dangerouslySetInnerHTML — this wipes any previously-bound
        // node-level listeners but not document-level delegation.
        const host = document.getElementById('metaboxes')!;
        host.innerHTML = `
            <table><tbody><tr>
                <td class="mua-media-field" data-target="mua_branding_splash_url">
                    <input type="hidden" id="mua_branding_splash_url" name="mua_branding_splash_url" value="">
                    <button type="button" class="button mua-media-upload-btn">Choose Image</button>
                    <button type="button" class="button mua-media-remove-btn" style="display:none">Remove</button>
                </td>
            </tr></tbody></table>
        `;

        const { frame, fire } = stubWpMedia('https://example.com/splash.png');
        document.querySelector<HTMLButtonElement>('.mua-media-upload-btn')!.click();
        expect(frame.open).toHaveBeenCalled();
        fire();

        expect((document.getElementById('mua_branding_splash_url') as HTMLInputElement).value)
            .toBe('https://example.com/splash.png');
    });

    it('remove button clears the hidden input and hides the preview', () => {
        document.body.innerHTML = `
            <table><tbody><tr>
                <td class="mua-media-field" data-target="mua_branding_logo_url">
                    <input type="hidden" id="mua_branding_logo_url" value="https://example.com/logo.png">
                    <img class="mua-media-preview" src="https://example.com/logo.png" alt="">
                    <button type="button" class="mua-media-upload-btn">Choose Image</button>
                    <button type="button" class="mua-media-remove-btn">Remove</button>
                </td>
            </tr></tbody></table>
        `;
        initMediaUploads();

        document.querySelector<HTMLButtonElement>('.mua-media-remove-btn')!.click();

        expect((document.getElementById('mua_branding_logo_url') as HTMLInputElement).value).toBe('');
        expect(document.querySelector('.mua-media-preview')).toBeNull();
    });

    it('logs an error instead of throwing when wp.media is missing', () => {
        renderBrandingField();
        initMediaUploads();
        // Note: no wp stub.
        const err = vi.spyOn(console, 'error').mockImplementation(() => {});

        expect(() => {
            document.querySelector<HTMLButtonElement>('.mua-media-upload-btn')!.click();
        }).not.toThrow();
        expect(err).toHaveBeenCalled();
        err.mockRestore();
    });

    it('restores stashed Underscore on window._ when a third-party script has overwritten it', () => {
        // 1. Genuine Underscore is on window._ at module-load time.
        const genuine = { defaults: vi.fn((o: Record<string, unknown>) => o) };
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (window as any)._ = genuine;
        stashUnderscore();

        // 2. A misbehaving plugin loads later and overwrites window._.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (window as any)._ = { not: 'underscore' };

        // 3. User clicks "Choose Image" — the handler must swap genuine
        // underscore back onto window._ before invoking wp.media.
        renderBrandingField();
        initMediaUploads();
        const { frame } = stubWpMedia('https://example.com/icon.png');
        // stubWpMedia overrode window._ with a test stub; simulate the
        // third-party overwrite occurring after that.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (window as any)._ = { not: 'underscore' };

        document.querySelector<HTMLButtonElement>('.mua-media-upload-btn')!.click();

        expect(frame.open).toHaveBeenCalled();
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        expect((window as any)._).toBe(genuine);
    });
});
