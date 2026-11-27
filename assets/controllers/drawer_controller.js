import { Controller } from '@hotwired/stimulus';

/*
 * A side drawer (design-ui.md § 4.14): no backdrop, the screen stays readable and usable. Opened by
 * a px:open event ([data-px-open]), closed by its cross or Escape (px:close from the shortcuts
 * controller); focus goes in on opening and back to the opener on closing.
 */
export default class extends Controller {
    connect() {
        this.onOpen = (event) => this.open(event);
        this.onClose = () => this.close();
        this.element.addEventListener('px:open', this.onOpen);
        this.element.addEventListener('px:close', this.onClose);
    }

    disconnect() {
        this.element.removeEventListener('px:open', this.onOpen);
        this.element.removeEventListener('px:close', this.onClose);
    }

    open(event) {
        this.opener = event?.detail?.opener ?? document.activeElement;
        this.element.classList.add('is-open');
        this.element.removeAttribute('inert');
        this.opener?.setAttribute?.('aria-expanded', 'true');
        this.element.querySelector('h2, [autofocus]')?.focus();
    }

    close() {
        this.element.classList.remove('is-open');
        this.element.setAttribute('inert', '');
        this.opener?.setAttribute?.('aria-expanded', 'false');
        if (this.opener instanceof HTMLElement && this.opener.isConnected) {
            this.opener.focus();
        }
    }
}
