import { beforeEach, describe, expect, it } from 'vitest';
import { initScreenConfig } from '@editorial/screen-config';

function metaboxFixture(initial: string): void {
    document.body.innerHTML = `
        <table class="form-table">
            <tr>
                <th>Content source</th>
                <td>
                    <select id="mua_route_type" name="mua_route_type">
                        <option value="none" ${initial === 'none' ? 'selected' : ''}>None</option>
                        <option value="standard" ${initial === 'standard' ? 'selected' : ''}>Standard</option>
                        <option value="custom" ${initial === 'custom' ? 'selected' : ''}>Custom</option>
                    </select>
                </td>
            </tr>
            <tr class="mua-screen-route-fields mua-screen-route-fields--standard" hidden>
                <th>Post type</th><td><select name="mua_route_post_type"></select></td>
            </tr>
            <tr class="mua-screen-route-fields mua-screen-route-fields--custom" hidden>
                <th>Provider</th><td><select name="mua_route_custom_id"></select></td>
            </tr>
        </table>
    `;
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('initScreenConfig — route type switches sub-field visibility', () => {
    it('reveals standard fields when set to standard, custom when custom', () => {
        metaboxFixture('none');
        initScreenConfig();

        const select = document.getElementById('mua_route_type') as HTMLSelectElement;
        const standard = document.querySelector<HTMLElement>('.mua-screen-route-fields--standard')!;
        const custom = document.querySelector<HTMLElement>('.mua-screen-route-fields--custom')!;

        expect(standard.hidden).toBe(true);
        expect(custom.hidden).toBe(true);

        select.value = 'standard';
        select.dispatchEvent(new Event('change'));
        expect(standard.hidden).toBe(false);
        expect(custom.hidden).toBe(true);

        select.value = 'custom';
        select.dispatchEvent(new Event('change'));
        expect(standard.hidden).toBe(true);
        expect(custom.hidden).toBe(false);
    });

    it('applies the initial selection on load (no change event needed)', () => {
        metaboxFixture('standard');
        initScreenConfig();

        const standard = document.querySelector<HTMLElement>('.mua-screen-route-fields--standard')!;
        expect(standard.hidden).toBe(false);
    });

    it('is a no-op when the metabox is not on the page', () => {
        document.body.innerHTML = '<p>Some other page</p>';
        expect(() => initScreenConfig()).not.toThrow();
    });
});
