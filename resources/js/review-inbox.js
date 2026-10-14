/**
 * MK-20: the review inbox's Alpine component (resources/views/livewire/marketing/review.blade.php). Pure UI, no round
 * trip: which date row is focused (←/→, or a click), the 1/2/3 and R keys acting on that row's own buttons, and the
 * Reject all confirm with its optional shared reason. Every write is the row's Livewire button, so a key does exactly
 * what the click does.
 *
 * Focus is an index into the rows as they are now. After Livewire re-renders (a Keep removes the row), the index is
 * kept, so the next date slides into the ring where the eye already is, clamped to the rows left.
 */
function reviewInbox() {
    return {
        /** The focused row's index. */
        focus: 0,
        /** The date whose Reject all confirm is open: {campaign, date, day, count, name}, or null. */
        rejecting: null,
        /** The confirm's shared reason, or null. */
        why: null,

        init() {
            this.paint();
            // After each of this component's updates has been morphed in, repaint the ring on the rows now there.
            window.Livewire.hook('commit', ({ component, succeed }) => {
                if (component.id !== this.$wire.$id) return;
                succeed(() => queueMicrotask(() => this.paint(true)));
            });
        },

        /** The date rows, in page order. */
        rows() {
            return [...this.$root.querySelectorAll('[data-review-row]')];
        },

        /**
         * Mark the focused row (clamped to the rows there are). `settle` after an update: when the element that had
         * focus went with its row (Keep), focus the row that took its place, so the keys keep working.
         */
        paint(settle = false) {
            const rows = this.rows();
            this.focus = Math.min(this.focus, Math.max(0, rows.length - 1));
            rows.forEach((row, i) => { row.dataset.focused = i === this.focus ? 'true' : 'false'; });
            const lost = !document.activeElement || document.activeElement === document.body || !document.activeElement.isConnected;
            if (settle && lost && !this.rejecting) rows[this.focus]?.focus({ preventScroll: true });
        },

        /** A click anywhere in a row focuses it; its buttons still do their own job. */
        pick(row) {
            const i = this.rows().indexOf(row);
            if (i >= 0 && i !== this.focus) {
                this.focus = i;
                this.paint();
            }
        },

        /** ←/→: the previous or next row, scrolled into view without moving the page more than needed. */
        move(step) {
            const rows = this.rows();
            if (rows.length === 0) return;
            this.focus = Math.min(rows.length - 1, Math.max(0, this.focus + step));
            this.paint();
            const row = rows[this.focus];
            row.focus({ preventScroll: true });
            const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            row.scrollIntoView({ block: 'nearest', behavior: smooth ? 'smooth' : 'auto' });
        },

        /** The page's keys. Never while typing, with a modifier, or while the confirm or the studio is open. */
        key(e) {
            if (this.rejecting || e.metaKey || e.ctrlKey || e.altKey) return;
            const active = document.activeElement;
            if (/INPUT|SELECT|TEXTAREA/.test(active?.tagName) || active?.isContentEditable) return;
            if (document.querySelector('[data-studio] [role="dialog"]')) return;

            const row = this.rows()[this.focus];
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                this.move(e.key === 'ArrowRight' ? 1 : -1);
            } else if (['1', '2', '3'].includes(e.key)) {
                this.press(e, row?.querySelector(`[data-keep-slot="${e.key}"]`));
            } else if (e.key === 'r' || e.key === 'R') {
                this.press(e, row?.querySelector('[data-reject-all]'));
            }
        },

        /** Click `button` for a key, unless it is missing or disabled (a draft still waiting for its photo). */
        press(e, button) {
            if (!button || button.disabled) return;
            e.preventDefault();
            button.click();
        },

        /** Open the Reject all confirm for one date, with no reason picked. */
        openReject(date) {
            this.why = null;
            this.rejecting = date;
        },

        /** "Reject all and redraft": close the confirm and send the date with the reason picked. */
        confirmReject() {
            const date = this.rejecting;
            this.rejecting = null;
            this.$wire.rejectAll(date.campaign, date.date, this.why);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('reviewInbox', reviewInbox);
});
