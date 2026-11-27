import { Controller } from '@hotwired/stimulus';

/*
 * Two-step confirmation (design-ui.md § 4.4, design-ux.md § 6.2): never confirm(), never a dialog.
 * First click arms the button (its label becomes the confirming one, it turns solid red, a quiet
 * « Annuler » appears on its left); a second click within 5 s does the gesture. Five seconds, a
 * loss of focus or Escape disarm it. Before arming it dispatches a cancelable confirm:arm event:
 * a container that prevents it lets the first click through (a dialog's « Annuler » with nothing typed).
 */
const ARMED_FOR = 5000;

export default class extends Controller {
    static targets = ['button', 'cancel', 'status'];

    connect() {
        this.armed = false;
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    click(event) {
        if (this.armed) {
            this.disarm(false);

            return;
        }
        if (this.dispatch('arm', { cancelable: true }).defaultPrevented) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        this.armed = true;
        this.element.classList.add('is-armed');
        this.cancelTarget.hidden = false;
        this.statusTarget.textContent = 'Cliquez de nouveau pour confirmer.';
        this.buttonTarget.focus();
        this.timer = setTimeout(() => this.disarm(), ARMED_FOR);
    }

    cancel(event) {
        event.preventDefault();
        this.disarm();
    }

    keydown(event) {
        if (event.key === 'Escape' && this.armed) {
            event.preventDefault();
            event.stopPropagation();
            this.disarm();
        }
    }

    blur(event) {
        if (this.armed && !this.element.contains(event.relatedTarget)) {
            this.disarm(false);
        }
    }

    disarm(refocus = true) {
        clearTimeout(this.timer);
        this.armed = false;
        this.element.classList.remove('is-armed');
        this.cancelTarget.hidden = true;
        this.statusTarget.textContent = '';
        if (refocus) {
            this.buttonTarget.focus();
        }
    }
}
