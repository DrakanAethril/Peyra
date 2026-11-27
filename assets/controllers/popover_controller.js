import { Controller } from '@hotwired/stimulus';

/*
 * A button and the panel it opens (design-ui.md § 4.2, the date chip's panel): not a menu - the
 * panel may hold text and a small form. The button toggles it; on opening, the panel's first field
 * (or the panel) takes the focus; Escape closes and gives the focus back to the button; a click or
 * a focus outside closes. The chip's one-time flash (`is-flash`) ends with its animation.
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
        this.buttonTarget.addEventListener('animationend', () => this.buttonTarget.classList.remove('is-flash'), { once: true });
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
        const field = this.panelTarget.querySelector('input:not([type="hidden"]), textarea, select, button');
        if (field) {
            field.focus();
        } else {
            this.panelTarget.setAttribute('tabindex', '-1');
            this.panelTarget.focus();
        }
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
            // The date field's calendar closes first: it stops the event when it is open.
            event.preventDefault();
            event.stopPropagation();
            this.close(true);
        }
    }
}
