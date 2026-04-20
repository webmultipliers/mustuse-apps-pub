import { describe, expect, it } from 'vitest';

/**
 * Smoke test — confirms the Vitest runner, jsdom environment, and TS path
 * resolution are wired up. Real frontend tests land here once the DOM
 * modules in assets/ expose pure, importable functions (see the companion
 * todo "Refactor developer/editorial main.ts to export testable units").
 */
describe('vitest harness', () => {
    it('runs inside a jsdom document', () => {
        document.body.innerHTML = '<div id="probe">ok</div>';
        expect(document.getElementById('probe')?.textContent).toBe('ok');
    });

    it('has DOM event plumbing', () => {
        const btn = document.createElement('button');
        let clicks = 0;
        btn.addEventListener('click', () => {
            clicks += 1;
        });
        btn.click();
        expect(clicks).toBe(1);
    });
});
