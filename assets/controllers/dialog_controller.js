import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/*
 * A native <dialog> opened with showModal() (design-ui.md § 4.14): focus trapped by the browser,
 * given back to the opener on close, Escape closes. With a src value, the body is a fragment the
 * server serves and takes back by fetch: a refusal answers the fragment again with a 422, a success
 * redirects, and the page follows the redirect. A dialog is for typing, never for confirming; Escape
 * over typed values arms the foot's two-step « Abandonner » (abandon target) instead of closing.
 */
export default class extends Controller {
    static targets = ['body', 'abandon'];
    static values = { src: String };

    connect() {
        this.onOpen = (event) => this.open(event);
        this.element.addEventListener('px:open', this.onOpen);
        this.element.addEventListener('close', () => this.restoreFocus());
        this.element.addEventListener('input', () => {
            this.dirty = true;
        });
        // Escape closes, except over typing in progress: then it arms « Abandonner » (two steps).
        this.element.addEventListener('cancel', (event) => {
            if (this.dirty && this.hasAbandonTarget) {
                event.preventDefault();
                this.abandonTarget.click();
            }
        });
    }

    disconnect() {
        this.element.removeEventListener('px:open', this.onOpen);
    }

    async open(event) {
        this.opener = event?.detail?.opener ?? document.activeElement;
        if (this.srcValue && this.hasBodyTarget) {
            await this.load(this.srcValue);
        }
        this.element.showModal();
    }

    close() {
        this.element.close();
    }

    /** « Annuler » asks for a second click only over typed values (confirm:arm). */
    arming(event) {
        if (!this.dirty) {
            event.preventDefault();
        }
    }

    /** A click on the backdrop (the dialog element itself, outside its box) closes it. */
    backdrop(event) {
        if (event.target === this.element) {
            this.close();
        }
    }

    async load(url) {
        this.bodyTarget.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' } });
            this.bodyTarget.innerHTML = await response.text();
        } catch {
            this.bodyTarget.innerHTML = '<p>Le contenu n’a pas pu être chargé. Fermez puis rouvrez la fenêtre.</p>';
        } finally {
            this.bodyTarget.removeAttribute('aria-busy');
        }
    }

    async submit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !this.srcValue) {
            return;
        }
        event.preventDefault();
        const submitter = event.submitter;
        submitter?.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(form.action, {
                method: form.method.toUpperCase(),
                body: new FormData(form, submitter ?? undefined),
                headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' },
            });
            if (response.redirected) {
                this.element.close();
                Turbo.visit(response.url);

                return;
            }
            this.bodyTarget.innerHTML = await response.text();
            this.bodyTarget.querySelector('[aria-invalid="true"]')?.focus();
        } finally {
            submitter?.removeAttribute('aria-busy');
        }
    }

    restoreFocus() {
        this.dirty = false;
        if (this.opener instanceof HTMLElement && this.opener.isConnected) {
            this.opener.focus();
        }
    }
}
