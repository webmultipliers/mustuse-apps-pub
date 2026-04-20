import { beforeEach, describe, expect, it } from 'vitest';
import { initDeploymentMap, updateDeploymentMap } from '@editorial/deployment-map';

function mapFixture(): void {
    document.body.innerHTML = `
        <div id="mua-dep-map" data-app-id="42">
            <div class="mua-dep-node mua-dep-node--complete" data-node="1" tabindex="0" role="button">
                <span class="mua-dep-node__label">WordPress</span>
                <div class="mua-dep-node__popup" hidden>
                    <ul class="mua-dep-node__checks">
                        <li class="mua-dep-node__check mua-dep-node__check--pass">Screen configured</li>
                    </ul>
                </div>
            </div>
            <div class="mua-dep-map__connector mua-dep-map__connector--idle" data-from="1" data-to="2"></div>
            <div class="mua-dep-node mua-dep-node--idle" data-node="2" tabindex="0" role="button">
                <span class="mua-dep-node__label">Assembler</span>
            </div>
            <div class="mua-dep-map__connector mua-dep-map__connector--idle" data-from="2" data-to="3"></div>
            <div class="mua-dep-node mua-dep-node--idle" data-node="3" tabindex="0" role="button">
                <span class="mua-dep-node__label">GitHub</span>
            </div>
            <div class="mua-dep-map__connector mua-dep-map__connector--idle" data-from="3" data-to="4"></div>
            <div class="mua-dep-node mua-dep-node--waiting" data-node="4" tabindex="0" role="button">
                <span class="mua-dep-node__label">Bifrost</span>
            </div>
            <div class="mua-dep-map__connector mua-dep-map__connector--idle" data-from="4" data-to="5"></div>
            <div class="mua-dep-node mua-dep-node--idle" data-node="5" tabindex="0" role="button">
                <span class="mua-dep-node__label">Device</span>
            </div>
        </div>
    `;
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('initDeploymentMap', () => {
    it('is a no-op when the map container is absent', () => {
        expect(() => initDeploymentMap()).not.toThrow();
    });

    it('toggles a node popup on click', () => {
        mapFixture();
        initDeploymentMap();

        const node1 = document.querySelector('[data-node="1"]') as HTMLElement;
        const popup = node1.querySelector('.mua-dep-node__popup') as HTMLElement;

        expect(popup.hidden).toBe(true);
        node1.click();
        expect(popup.hidden).toBe(false);
        node1.click();
        expect(popup.hidden).toBe(true);
    });

    it('closes other popups when a different node is clicked', () => {
        mapFixture();
        initDeploymentMap();

        const node1 = document.querySelector('[data-node="1"]') as HTMLElement;
        const node2 = document.querySelector('[data-node="2"]') as HTMLElement;
        const popup1 = node1.querySelector('.mua-dep-node__popup') as HTMLElement;

        node1.click();
        expect(popup1.hidden).toBe(false);

        node2.click();
        expect(popup1.hidden).toBe(true);
    });
});

describe('updateDeploymentMap', () => {
    it('sets node classes to match the "pending" status', () => {
        mapFixture();

        updateDeploymentMap('pending');

        const node2 = document.querySelector('[data-node="2"]') as HTMLElement;
        expect(node2.classList.contains('mua-dep-node--active')).toBe(true);
        expect(node2.classList.contains('mua-dep-node--idle')).toBe(false);

        const conn2 = document.querySelector('[data-to="2"]') as HTMLElement;
        expect(conn2.classList.contains('mua-dep-map__connector--active')).toBe(true);
    });

    it('sets all nodes to "complete" for the "complete" status', () => {
        mapFixture();

        updateDeploymentMap('complete');

        for (let n = 1; n <= 5; n++) {
            const node = document.querySelector(`[data-node="${n}"]`) as HTMLElement;
            expect(node.classList.contains('mua-dep-node--complete')).toBe(true);
        }
    });

    it('marks node 2 as "failed" for the "failed" status', () => {
        mapFixture();

        updateDeploymentMap('failed');

        const node2 = document.querySelector('[data-node="2"]') as HTMLElement;
        expect(node2.classList.contains('mua-dep-node--failed')).toBe(true);
    });

    it('marks nodes 2+3 as "complete" for "projected"', () => {
        mapFixture();

        updateDeploymentMap('projected');

        expect(
            (document.querySelector('[data-node="2"]') as HTMLElement).classList.contains('mua-dep-node--complete')
        ).toBe(true);
        expect(
            (document.querySelector('[data-node="3"]') as HTMLElement).classList.contains('mua-dep-node--complete')
        ).toBe(true);
        expect(
            (document.querySelector('[data-node="4"]') as HTMLElement).classList.contains('mua-dep-node--waiting')
        ).toBe(true);
    });

    it('falls back to idle for unknown status', () => {
        mapFixture();

        updateDeploymentMap('some_unknown_value');

        const node2 = document.querySelector('[data-node="2"]') as HTMLElement;
        expect(node2.classList.contains('mua-dep-node--idle')).toBe(true);
    });
});
