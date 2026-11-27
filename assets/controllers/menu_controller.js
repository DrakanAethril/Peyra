import { Controller } from '@hotwired/stimulus';

/*
 * A menu button and its popover (design-ui.md § 4.6, the avatar's menu of design-ux.md § 2.1).
 * The button toggles it; on opening the first entry takes the focus; ↑ ↓ Home End move between the
 * entries, Escape closes and gives the focus back to the button, a click or a focus outside closes.
 */
export default class extends Controller {
    static targets = ['button', 'panel'];

    connect() {
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.close(false);
            }
        };
        document.addEventListener('pointerdown', this.onOutside);
        document.addEventListener('focusin', this.onOutside);
    }

    disconnect() {
        document.removeEventListener('pointerdown', this.onOutside);
        document.removeEventListener('focusin', this.onOutside);
    }

    toggle() {
        if (this.panelTarget.hidden) {
            this.open();
        } else {
            this.close(true);
        }
    }

    open() {
        this.panelTarget.hidden = false;
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.entries()[0]?.focus();
    }

    close(refocus) {
        if (this.panelTarget.hidden) {
            return;
        }
        this.panelTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        if (refocus) {
            this.buttonTarget.focus();
        }
    }

    keydown(event) {
        if (event.key === 'Escape' && !this.panelTarget.hidden) {
            event.preventDefault();
            event.stopPropagation();
            this.close(true);

            return;
        }
        if (event.key === 'ArrowDown' && this.panelTarget.hidden && event.target === this.buttonTarget) {
            event.preventDefault();
            this.open();

            return;
        }
        if (this.panelTarget.hidden || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            return;
        }
        event.preventDefault();
        const entries = this.entries();
        const index = entries.indexOf(document.activeElement);
        const next = {
            ArrowDown: index + 1 < entries.length ? index + 1 : 0,
            ArrowUp: index > 0 ? index - 1 : entries.length - 1,
            Home: 0,
            End: entries.length - 1,
        }[event.key];
        entries[next]?.focus();
    }

    entries() {
        return [...this.panelTarget.querySelectorAll('[role="menuitem"]')];
    }
}
