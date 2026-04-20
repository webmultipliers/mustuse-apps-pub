import {
    initBrandingPreview,
    initBuildStatusPoller,
    initMediaUploads,
    initShipIt,
    initTestConnection,
    stashUnderscore,
} from './init';
import { initDeploymentMap } from './deployment-map';
import { initScreenConfig } from './screen-config';
import { initScreenTree } from './screen-tree';

// Must run at module-eval time, before any third-party script can replace
// `window._`. The `underscore` handle is declared as a hard dep of this
// bundle in EditorialController::enqueueAssets, so by the time this module
// runs, `window._` is genuine Underscore.
stashUnderscore();

function onReady(fn: () => void): void {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
    } else {
        fn();
    }
}

onReady(() => {
    initMediaUploads();
    initBrandingPreview();
    initShipIt();
    initBuildStatusPoller();
    initTestConnection();
    initDeploymentMap();
    initScreenConfig();
    initScreenTree();
});
