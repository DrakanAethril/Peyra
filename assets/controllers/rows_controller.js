import { Controller } from '@hotwired/stimulus';

/*
 * Keyboard reading of a list (design-ux.md § 5.1, « Listes »; design-ui.md § 4.7): one row in the
 * tab order (tabindex="0" on the current one), ↑ ↓ Home End move it, Enter opens the row's link,
 * Space ticks the row's box when it has one (a change event tells the selection). Rows that take
 * part carry data-rows-target="row".
 */
export default class extends Controller {
    static targets = ['row'];

    connect() {
        const rows = this.rowTargets;
        const current = rows.find((row) => row.classList.contains('is-current')) ?? rows[0];
        rows.forEach((row) => row.setAttribute('tabindex', row === current ? '0' : '-1'));
    }

    keydown(event) {
        const rows = this.rowTargets;
        const index = rows.indexOf(event.target);
        if (index === -1) {
            return;
        }
        const moves = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: rows.length - 1 };
        if (event.key in moves) {
            event.preventDefault();
            this.move(rows[Math.max(0, Math.min(rows.length - 1, moves[event.key]))]);
        } else if (event.key === 'Enter' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey) {
            const link = event.target.querySelector('a[href]');
            if (link) {
                event.preventDefault();
                link.click();
            }
        } else if (event.key === ' ') {
            const box = event.target.querySelector('input[type="checkbox"]');
            if (box) {
                event.preventDefault();
                if (!box.disabled) {
                    box.checked = !box.checked;
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        }
    }

    move(row) {
        this.rowTargets.forEach((other) => {
            other.setAttribute('tabindex', other === row ? '0' : '-1');
            other.classList.toggle('is-current', other === row);
            other.setAttribute('aria-selected', String(other === row));
        });
        row.focus();
    }
}
