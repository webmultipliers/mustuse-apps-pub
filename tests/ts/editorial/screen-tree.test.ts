import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flattenTree, initScreenTree } from '@editorial/screen-tree';

function buildFixture(): HTMLElement {
    document.body.innerHTML = `
        <div class="mua-screen-tree" data-app-id="42">
            <ol class="mua-screen-tree__list" data-parent-id="0">
                <li class="mua-screen-tree__item" data-screen-id="10">
                    <div class="mua-screen-tree__row"></div>
                    <ol class="mua-screen-tree__list" data-parent-id="10">
                        <li class="mua-screen-tree__item" data-screen-id="11">
                            <div class="mua-screen-tree__row"></div>
                            <ol class="mua-screen-tree__list" data-parent-id="11"></ol>
                        </li>
                        <li class="mua-screen-tree__item" data-screen-id="12">
                            <div class="mua-screen-tree__row"></div>
                            <ol class="mua-screen-tree__list" data-parent-id="12"></ol>
                        </li>
                    </ol>
                </li>
                <li class="mua-screen-tree__item" data-screen-id="20">
                    <div class="mua-screen-tree__row"></div>
                    <ol class="mua-screen-tree__list" data-parent-id="20"></ol>
                </li>
            </ol>
        </div>
    `;
    return document.querySelector<HTMLElement>('.mua-screen-tree')!;
}

beforeEach(() => {
    document.body.innerHTML = '';
});

afterEach(() => {
    delete (window as Window).wp;
    delete (window as Window).MUA_EDITORIAL;
});

describe('initScreenTree + Add Screen', () => {
    function treeWithAddButton(): void {
        document.body.innerHTML = `
            <div class="mua-screen-tree" data-app-id="42">
                <div class="mua-screen-tree__toolbar">
                    <button type="button" class="mua-screen-tree__add" data-parent-id="0">+ Add</button>
                    <span class="mua-screen-tree__status"></span>
                </div>
                <ol class="mua-screen-tree__list" data-parent-id="0"></ol>
            </div>
        `;
    }

    it('wires the add-button click handler even when wp.apiFetch is missing at init', () => {
        treeWithAddButton();

        // wp.apiFetch NOT yet attached — mirrors Gutenberg's async-init race.
        initScreenTree();

        // Now simulate apiFetch arriving later (after Gutenberg booted).
        const apiFetch = vi.fn().mockResolvedValue({ screen_id: 1, edit_url: '/x' });
        (window as Window).wp = { apiFetch };
        (window as Window).MUA_EDITORIAL = { restBase: 'r', nonce: 'n' };

        vi.spyOn(window, 'prompt').mockReturnValue('Home');
        const originalHref = window.location.href;
        // Stub window.location so the handler's assignment doesn't actually navigate.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (window as any).location = { ...window.location, href: originalHref };

        const btn = document.querySelector<HTMLButtonElement>('.mua-screen-tree__add')!;
        btn.click();

        // Click handler must have fired — regression test for the silent
        // early-exit that previously left the button dead when apiFetch
        // wasn't available at DOMContentLoaded.
        expect(window.prompt).toHaveBeenCalled();
    });

    it('shows an alert when wp.apiFetch is still missing at click time', () => {
        treeWithAddButton();
        initScreenTree();

        vi.spyOn(window, 'prompt').mockReturnValue('Home');
        const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

        document.querySelector<HTMLButtonElement>('.mua-screen-tree__add')!.click();

        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('block editor APIs'));
    });
});

describe('flattenTree', () => {
    it('emits one row per item with the enclosing list parent and the DOM index as order', () => {
        const container = buildFixture();
        const rows = flattenTree(container);

        expect(rows).toEqual([
            { id: 10, parent: 0, order: 0 },
            { id: 20, parent: 0, order: 1 },
            { id: 11, parent: 10, order: 0 },
            { id: 12, parent: 10, order: 1 },
        ]);
    });

    it('reads parent from the enclosing <ol> after a drag re-parents the item', () => {
        const container = buildFixture();
        // Simulate SortableJS moving #11 from inside #10's children up to root.
        const rootList = container.querySelector<HTMLOListElement>(
            'ol.mua-screen-tree__list[data-parent-id="0"]',
        )!;
        const item11 = container.querySelector<HTMLLIElement>('li[data-screen-id="11"]')!;
        rootList.appendChild(item11);

        const rows = flattenTree(container);

        const row11 = rows.find((r) => r.id === 11);
        expect(row11?.parent).toBe(0);
        // Item 11 lands at the end of the root list (after 10 and 20).
        expect(row11?.order).toBe(2);
    });

    it("syncs each item's child <ol> data-parent-id to the item's id so descendants don't drift", () => {
        const container = buildFixture();
        // Move #11 (with its now-empty child <ol>) into #20 — its child list's
        // data-parent-id was "11" before the move and stays correct, but
        // flattenTree should *also* repair the child list's data-parent-id
        // for any item whose own id was moved (defensive — keeps subsequent
        // re-flattens stable even if SortableJS doesn't update data-attrs).
        const list20 = container.querySelector<HTMLOListElement>(
            'ol.mua-screen-tree__list[data-parent-id="20"]',
        )!;
        const item11 = container.querySelector<HTMLLIElement>('li[data-screen-id="11"]')!;
        list20.appendChild(item11);

        flattenTree(container);

        const childOf11 = item11.querySelector<HTMLOListElement>(
            ':scope > ol.mua-screen-tree__list',
        )!;
        expect(childOf11.dataset.parentId).toBe('11');
    });

    it('returns an empty array when no items are present', () => {
        document.body.innerHTML = `
            <div class="mua-screen-tree" data-app-id="1">
                <ol class="mua-screen-tree__list" data-parent-id="0"></ol>
            </div>
        `;
        const container = document.querySelector<HTMLElement>('.mua-screen-tree')!;
        expect(flattenTree(container)).toEqual([]);
    });
});
