/**
 * The mockup gallery and viewer's client side (SB-21, option A). Pure UI: filters, option tabs,
 * side-by-side panes, the width switch and frame scaling never call the server. The one server
 * call is the pick, made from the viewer's dialog through $wire.
 */

/**
 * Scale a page rendered at `width()` CSS px into this element's box: never enlarge, and centre a
 * narrow page (375/768) in a wide box. The frame keeps its real viewport width, so the mockup's
 * own breakpoints apply; only the picture is scaled.
 *
 * @param {() => number} width the page width to render at (the viewer's width switch)
 */
export function mockupFit(width) {
    return {
        bw: 0,
        bh: 0,
        init() {
            const measure = () => { this.bw = this.$el.clientWidth; this.bh = this.$el.clientHeight; };
            measure();
            new ResizeObserver(measure).observe(this.$el);
        },
        get frameStyle() {
            const w = width();
            if (!this.bw) return { width: w + 'px', height: '100%' };
            const s = Math.min(1, this.bw / w);
            return {
                width: w + 'px',
                height: this.bh / s + 'px',
                transform: `translateX(${Math.max(0, (this.bw - w * s) / 2)}px) scale(${s})`,
            };
        },
    };
}

/**
 * The gallery's filters: status, project and a search over ID and title. Each project group
 * shows its first `perGroup` cards until "Show all"; a search or a status filter shows every match.
 *
 * @param {string} project the project to start on ('' for all)
 * @param {number} perGroup cards a group shows before "Show all"
 * @param {Array<{project: string, story: string, title: ?string, state: string}>} sets every set on the page
 */
export function mockupGallery(project, perGroup, sets) {
    return {
        sets,
        status: 'all',
        project,
        q: '',
        expanded: [],
        matches(set) {
            const q = this.q.trim().toLowerCase();
            return (this.status === 'all' || set.state === this.status)
                && (this.project === '' || set.project === this.project)
                && (q === '' || (set.story + ' ' + (set.title || '')).toLowerCase().includes(q));
        },
        shown(set, rank) {
            const narrowed = this.q.trim() !== '' || this.status !== 'all';
            return this.matches(set) && (narrowed || rank < perGroup || this.expanded.includes(set.project));
        },
        get none() { return !this.sets.some((s) => this.matches(s)); },
        clear() { this.status = 'all'; this.project = ''; this.q = ''; },
    };
}

/**
 * The full-screen viewer: one option at a time (tabs), or two side by side with a picker per pane
 * and a swap; the width switch; Escape back to the gallery; the pick dialog.
 *
 * @param {{options: string[], first: string, urls: Object<string, string>, gallery: string, prev: string, next: string}} cfg
 */
export function mockupViewer(cfg) {
    return {
        opt: cfg.first,
        compare: false,
        left: cfg.first,
        right: cfg.options.find((o) => o !== cfg.first) || 'current',
        width: 1280,
        picking: null,
        reason: '',
        url(k) { return cfg.urls[k] || null; },
        label(k) { return k === 'current' ? 'Current' : 'Option ' + k.toUpperCase(); },
        swap() { [this.left, this.right] = [this.right, this.left]; },
        openPick(k) { this.picking = k; this.reason = ''; this.$nextTick(() => this.$refs.reason?.focus()); },
        async confirmPick() {
            await this.$wire.pick(this.picking, this.reason);
            if (!this.$wire.refusal) this.picking = null;
        },
        escape() {
            if (this.picking) { this.picking = null; return; }
            window.Livewire.navigate(cfg.gallery);
        },
        step(e, to) {
            // Arrow keys move between sets, but never while typing or choosing in a select.
            if (this.picking || /SELECT|INPUT|TEXTAREA/.test(document.activeElement?.tagName)) return;
            e.preventDefault();
            window.Livewire.navigate(to === 'next' ? cfg.next : cfg.prev);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mockupFit', mockupFit);
    window.Alpine.data('mockupGallery', mockupGallery);
    window.Alpine.data('mockupViewer', mockupViewer);
});
