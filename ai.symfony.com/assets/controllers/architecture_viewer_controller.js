import { Controller } from '@hotwired/stimulus';

const FALLBACK_HEIGHT = 500;
const HEIGHT_BUFFER = 4;
// archify's own viewer partly sizes its layout off the iframe's current height
// (its "available viewport"), so a naive "measure, grow, re-measure" loop never
// reaches a fixed point: more height makes the inner document ask for more height
// again, without bound. Giving it a generous height up front first, then measuring
// once it settles and disconnecting for good, breaks that loop instead of chasing it.
const SETTLE_HEIGHT = 3000;
// No two resize bursts in a row within this window: treat it as settled.
const SETTLE_QUIET_MS = 300;
// Absolute cap in case the inner layout never stops growing on its own; without
// this, a persistent growth cause (not just our own feedback) would hang forever.
const SETTLE_TIMEOUT_MS = 2000;
const RESIZE_DEBOUNCE_MS = 200;

export default class extends Controller {
    static targets = ['frame'];

    connect() {
        // Set once from the site's current theme. archify's own widget has its own
        // independent light/dark toggle; reloading the iframe on every later site
        // theme change would destroy zoom/focus/passport state for a sync that
        // duplicates a control the widget already offers.
        const theme = document.documentElement.getAttribute('data-bs-theme') || 'light';
        const url = new URL(this.frameTarget.src, window.location.href);
        url.searchParams.set('theme', theme);
        this.frameTarget.src = url.toString();

        this.frameTarget.addEventListener('load', () => this.settle());
        this.onWindowResize = () => this.debounceResettle();
        window.addEventListener('resize', this.onWindowResize);
    }

    disconnect() {
        this.resizeObserver?.disconnect();
        clearTimeout(this.quietTimer);
        clearTimeout(this.hardTimer);
        clearTimeout(this.resizeDebounce);
        window.removeEventListener('resize', this.onWindowResize);
    }

    debounceResettle() {
        clearTimeout(this.resizeDebounce);
        this.resizeDebounce = setTimeout(() => this.settle(), RESIZE_DEBOUNCE_MS);
    }

    settle() {
        let doc;
        try {
            doc = this.frameTarget.contentDocument;
        } catch {
            doc = null;
        }

        if (!doc?.documentElement) {
            this.frameTarget.style.height = FALLBACK_HEIGHT + 'px';

            return;
        }

        this.resizeObserver?.disconnect();
        clearTimeout(this.quietTimer);
        clearTimeout(this.hardTimer);

        // Give the inner layout more room than any real diagram needs, so it has
        // no reason to keep asking for more while we watch it settle.
        this.frameTarget.style.height = SETTLE_HEIGHT + 'px';

        const finalize = () => {
            this.resizeObserver?.disconnect();
            clearTimeout(this.quietTimer);
            clearTimeout(this.hardTimer);

            const height = this.measureContentHeight(doc);
            this.frameTarget.style.height = (height > 0 ? height + HEIGHT_BUFFER : FALLBACK_HEIGHT) + 'px';
        };

        this.hardTimer = setTimeout(finalize, SETTLE_TIMEOUT_MS);
        this.resizeObserver = new ResizeObserver(() => {
            clearTimeout(this.quietTimer);
            this.quietTimer = setTimeout(finalize, SETTLE_QUIET_MS);
        });
        this.resizeObserver.observe(doc.documentElement);
        // Also start the quiet timer immediately: a diagram that never triggers a
        // single resize event still needs to be measured and locked in.
        this.quietTimer = setTimeout(finalize, SETTLE_QUIET_MS);
    }

    // `documentElement`/`body` scrollHeight is useless here: archify's body has a
    // `min-height: 100vh` rule, so it always reports back roughly whatever height
    // the iframe currently has, not the diagram's real extent (confirmed live: a
    // 3000px iframe measures a ~3000px body every time). The actual content sits
    // in a fixed set of direct body children (toolbar, `.container`, a toast),
    // so the true bottom edge is the furthest-down child, not the stretched body.
    measureContentHeight(doc) {
        const children = [...doc.body.children];
        const bottoms = children
            .map((el) => el.getBoundingClientRect().bottom)
            .filter((bottom) => bottom > 0);

        return bottoms.length > 0 ? Math.max(...bottoms) : doc.documentElement.scrollHeight;
    }
}
