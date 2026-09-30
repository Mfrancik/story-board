/**
 * The App map tab's client side (SB-24, option A, the storyboard strip). Pure UI: every journey,
 * step and picture is already in the page, rendered by the server once; picking a flow, stepping
 * Back/Next (buttons or ← →), full screen and the shared-screen hover never call the server.
 *
 * @param {number[]} counts how many steps each journey has, in page order
 */
export function appMap(counts) {
    return {
        /** The flow on screen: -1 is All flows (the overview), else a journey's index. */
        j: -1,
        /** The step on the stage, 0-based, within flow `j`. */
        i: 0,
        /** The stage is shown full screen. */
        lb: false,
        /** The shared route under the pointer in All flows, lit in every lane. */
        hot: null,

        get last() { return this.j < 0 ? 0 : counts[this.j] - 1; },
        get atFirst() { return this.i <= 0; },
        get atLast() { return this.i >= this.last; },

        /** Show flow `k` (or All flows for -1) from step `i`. */
        show(k, i = 0) {
            this.j = k;
            this.i = Math.max(0, Math.min(i, this.last));
            this.lb = false;
            this.hot = null;
            this.$nextTick(() => this.centre());
        },
        /** Open a screen from All flows or the shared list: its flow, at that step, back at the top. */
        jump(k, i) {
            this.show(k, i);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        /** Step by `d`, clamped: Next on the last step stays there (and is disabled). */
        go(d) {
            if (this.j < 0) return;
            this.i = Math.max(0, Math.min(this.last, this.i + d));
            this.$nextTick(() => this.centre());
        },
        open() { if (this.j >= 0) this.lb = true; },
        close() { this.lb = false; },
        key(e) {
            if (e.key === 'Escape') { this.close(); return; }
            // Arrows step, but never while typing or choosing in a field.
            if (this.j < 0 || /INPUT|SELECT|TEXTAREA/.test(document.activeElement?.tagName)) return;
            if (e.key === 'ArrowRight') { e.preventDefault(); this.go(1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); this.go(-1); }
        },
        /** Scroll the storyboard strip sideways so the current screen is in view; never moves the page. */
        centre() {
            const strip = this.$root.querySelector('[data-strip]');
            const cur = strip?.querySelector('[aria-current="true"]');
            if (cur) strip.scrollLeft = cur.offsetLeft - strip.clientWidth / 2 + cur.offsetWidth / 2;
        },
        /** Light a shared screen across lanes; `route` is null for a screen only one flow visits. */
        light(route) { this.hot = route; },
        dim(route) { return this.hot !== null && this.hot !== route; },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('appMap', appMap);
});
