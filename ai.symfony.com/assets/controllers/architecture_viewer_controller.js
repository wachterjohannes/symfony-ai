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
// The frame must not push the rest of the page off screen, so it never grows past
// the viewport minus the sticky site header minus a small breathing gutter.
const MIN_HEIGHT = 560;
const VIEWPORT_GUTTER = 24;
// Only used if the header is missing or not laid out yet; it measures 77px today.
const HEADER_FALLBACK_HEIGHT = 77;
// Long enough for archify to reflow its SVG into the height we just applied.
const CORRECTION_DELAY_MS = 250;
// Sub-pixel rounding and borders make a perfectly fitting document report a few
// stray pixels of overflow; below this it is not a real clip.
const OVERFLOW_TOLERANCE = 8;

export default class extends Controller {
    static targets = ['frame'];

    connect() {
        // Set once from the site's current theme. archify's own widget has its own
        // independent light/dark toggle; reloading the iframe on every later site
        // theme change would destroy zoom/focus/passport state for a sync that
        // duplicates a control the widget already offers. Later toggles are synced
        // in place instead, see #syncTheme.
        const theme = document.documentElement.getAttribute('data-bs-theme') || 'light';
        const url = new URL(this.frameTarget.src, window.location.href);
        url.searchParams.set('theme', theme);
        this.frameTarget.src = url.toString();

        // A page reached via a passport/guided-view deep link (?focus=/?view=) loads
        // at scroll 0 with the diagram below the fold; scroll it into view once the
        // iframe has settled to its final height, then never again (a later resize
        // resettle should not keep yanking the page back).
        const params = new URLSearchParams(window.location.search);
        this.hasDeepLink = params.has('focus') || params.has('view');

        this.frameTarget.addEventListener('load', () => this.settle());
        this.onWindowResize = () => this.debounceResettle();
        window.addEventListener('resize', this.onWindowResize);

        this.themeObserver = new MutationObserver(() => this.syncTheme());
        this.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-bs-theme'],
        });
    }

    disconnect() {
        this.resizeObserver?.disconnect();
        this.themeObserver?.disconnect();
        clearTimeout(this.quietTimer);
        clearTimeout(this.hardTimer);
        clearTimeout(this.resizeDebounce);
        clearTimeout(this.correctionTimer);
        window.removeEventListener('resize', this.onWindowResize);
    }

    // Tells the diagram to re-theme itself in place instead of reloading, so zoom,
    // focus and passport state survive a site-wide theme toggle. The injected
    // passport script inside the delivered diagram listens for this exact message
    // shape and applies it; a frame that hasn't loaded yet, or a cross-origin
    // rejection, is silently skipped since the next toggle will retry anyway.
    syncTheme() {
        const theme = document.documentElement.getAttribute('data-bs-theme') || 'light';
        try {
            this.frameTarget.contentWindow?.postMessage({ type: 'sf-ai-set-theme', theme }, window.location.origin);
        } catch {
            // Ignore: the next theme toggle will retry.
        }
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
        clearTimeout(this.correctionTimer);
        this.correctionDone = false;

        // Give the inner layout more room than any real diagram needs, so it has
        // no reason to keep asking for more while we watch it settle.
        this.frameTarget.style.height = SETTLE_HEIGHT + 'px';

        const finalize = () => {
            this.resizeObserver?.disconnect();
            clearTimeout(this.quietTimer);
            clearTimeout(this.hardTimer);

            const measured = this.measureContentHeight(doc);

            if (measured > 0) {
                this.frameTarget.style.height = Math.max(MIN_HEIGHT, Math.min(measured, this.availableHeight())) + 'px';
                this.correctOverflow(measured);
            } else {
                this.frameTarget.style.height = FALLBACK_HEIGHT + 'px';
            }

            if (this.hasDeepLink) {
                this.hasDeepLink = false;
                this.frameTarget.scrollIntoView({ block: 'start' });
            }
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

    // The site header is sticky, so it covers the top of the viewport at every
    // scroll position. Its height is read live because it differs per breakpoint.
    availableHeight() {
        const headerHeight = document.querySelector('header')?.getBoundingClientRect().height || HEADER_FALLBACK_HEIGHT;

        return window.innerHeight - headerHeight - VIEWPORT_GUTTER;
    }

    // archify reflows its SVG into whatever vertical budget the frame offers, so
    // most diagrams fit the capped frame with no inner scrollbar at all. The few
    // that genuinely cannot (the sequence diagram) would be clipped by the cap, so
    // once the reflow has run, give exactly those the height they still ask for.
    // Never more than the unconstrained measurement, and at most once per settle so
    // a diagram that reacts to its own new height cannot start a growth loop.
    correctOverflow(measured) {
        if (this.correctionDone) {
            return;
        }

        this.correctionDone = true;
        this.correctionTimer = setTimeout(() => {
            let root;
            try {
                root = this.frameTarget.contentDocument?.documentElement;
            } catch {
                root = null;
            }

            if (!root || root.scrollHeight <= root.clientHeight + OVERFLOW_TOLERANCE) {
                return;
            }

            this.frameTarget.style.height = Math.min(root.scrollHeight + HEIGHT_BUFFER, measured) + 'px';
        }, CORRECTION_DELAY_MS);
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
