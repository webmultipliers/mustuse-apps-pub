import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { activate, initScreenSwitcher, initSplash } from '@preview/main';

beforeEach(() => {
    document.body.innerHTML = '';
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('initSplash', () => {
    it('marks the overlay as hidden after the auto-dismiss timeout', () => {
        document.body.innerHTML = `
            <div data-mua-splash class="mua-preview-splash">
                <button data-mua-splash-skip>Skip</button>
            </div>
        `;
        const splash = document.querySelector<HTMLElement>('[data-mua-splash]')!;

        initSplash();
        expect(splash.classList.contains('is-hidden')).toBe(false);

        // Auto-dismiss fires at SPLASH_AUTODISMISS_MS (1500ms).
        vi.advanceTimersByTime(1500);
        expect(splash.classList.contains('is-hidden')).toBe(true);
    });

    it('dismisses immediately when the skip button is clicked', () => {
        document.body.innerHTML = `
            <div data-mua-splash class="mua-preview-splash">
                <button data-mua-splash-skip>Skip</button>
            </div>
        `;
        const splash = document.querySelector<HTMLElement>('[data-mua-splash]')!;
        const skip   = document.querySelector<HTMLButtonElement>('[data-mua-splash-skip]')!;

        initSplash();
        skip.click();

        expect(splash.classList.contains('is-hidden')).toBe(true);
    });

    it('is a no-op when the splash element is not present', () => {
        document.body.innerHTML = '<p>nothing here</p>';
        expect(() => initSplash()).not.toThrow();
    });
});

describe('activate', () => {
    function fixture(activeId: string): HTMLElement {
        document.body.innerHTML = `
            <div data-mua-frame>
                <div>
                    <article data-mua-screen-pane="100" ${activeId === '100' ? '' : 'hidden'}>Home</article>
                    <article data-mua-screen-pane="101" ${activeId === '101' ? '' : 'hidden'}>Profile</article>
                </div>
                <nav>
                    <button data-mua-nav-tab="100" class="${activeId === '100' ? 'is-active' : ''}">Home</button>
                    <button data-mua-nav-tab="101" class="${activeId === '101' ? 'is-active' : ''}">Profile</button>
                </nav>
            </div>
        `;
        return document.querySelector<HTMLElement>('[data-mua-frame]')!;
    }

    it('shows only the matching pane and mirrors the active tab', () => {
        const frame = fixture('100');

        activate(frame, '101');

        const home    = frame.querySelector<HTMLElement>('[data-mua-screen-pane="100"]')!;
        const profile = frame.querySelector<HTMLElement>('[data-mua-screen-pane="101"]')!;
        expect(home.hasAttribute('hidden')).toBe(true);
        expect(profile.hasAttribute('hidden')).toBe(false);

        const homeTab    = frame.querySelector<HTMLButtonElement>('[data-mua-nav-tab="100"]')!;
        const profileTab = frame.querySelector<HTMLButtonElement>('[data-mua-nav-tab="101"]')!;
        expect(homeTab.classList.contains('is-active')).toBe(false);
        expect(profileTab.classList.contains('is-active')).toBe(true);
    });
});

describe('initScreenSwitcher', () => {
    it('wires every nav-tab click to swap the matching pane', () => {
        document.body.innerHTML = `
            <div data-mua-frame>
                <div>
                    <article data-mua-screen-pane="200">Home</article>
                    <article data-mua-screen-pane="201" hidden>Profile</article>
                </div>
                <nav>
                    <button data-mua-nav-tab="200" class="is-active">Home</button>
                    <button data-mua-nav-tab="201">Profile</button>
                </nav>
            </div>
        `;
        const frame      = document.querySelector<HTMLElement>('[data-mua-frame]')!;
        const profileTab = frame.querySelector<HTMLButtonElement>('[data-mua-nav-tab="201"]')!;

        initScreenSwitcher();
        profileTab.click();

        expect(frame.querySelector<HTMLElement>('[data-mua-screen-pane="200"]')!.hasAttribute('hidden')).toBe(true);
        expect(frame.querySelector<HTMLElement>('[data-mua-screen-pane="201"]')!.hasAttribute('hidden')).toBe(false);
        expect(profileTab.classList.contains('is-active')).toBe(true);
    });

    it('is a no-op when there is no frame on the page', () => {
        document.body.innerHTML = '<p>nothing</p>';
        expect(() => initScreenSwitcher()).not.toThrow();
    });
});
