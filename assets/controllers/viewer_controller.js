import { Controller } from '@hotwired/stimulus';

/*
 * The viewer of a pièce (Px:Viewer, design-ui.md § 4.12). A picture is fitted to the width, then
 * zoomed from 50 to 200 % by steps of 25: « + », « − » and « 0 » when the stage has the focus,
 * Ctrl and the wheel, or the bar's buttons. A PDF is the browser's reader, which zooms and turns
 * its pages itself. « Voir la pièce en grand » (F7) lays the viewer over the whole window; Échap
 * or F7 again puts it back.
 */
const MIN = 50;
const MAX = 200;
const STEP = 25;

export default class extends Controller {
    static targets = ['stage', 'page', 'label', 'full'];

    connect() {
        this.zoom = 100;
        this.onKey = (event) => {
            if (event.key === 'Escape' && this.element.classList.contains('is-full')) {
                event.preventDefault();
                this.toggleFull();
            }
        };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    zoomIn() {
        this.apply(this.zoom + STEP);
    }

    zoomOut() {
        this.apply(this.zoom - STEP);
    }

    keydown(event) {
        if (event.ctrlKey || event.altKey || event.metaKey) {
            return;
        }
        if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            this.zoomIn();
        } else if (event.key === '-' || event.key === '−') {
            event.preventDefault();
            this.zoomOut();
        } else if (event.key === '0') {
            event.preventDefault();
            this.apply(100);
        }
    }

    wheel(event) {
        if (!event.ctrlKey) {
            return;
        }
        event.preventDefault();
        this.apply(this.zoom + (event.deltaY < 0 ? STEP : -STEP));
    }

    toggleFull() {
        const full = this.element.classList.toggle('is-full');
        if (this.hasFullTarget) {
            this.fullTarget.setAttribute('aria-pressed', full ? 'true' : 'false');
        }
        (this.stageTarget.hasAttribute('tabindex') ? this.stageTarget : this.stageTarget.querySelector('iframe'))?.focus();
    }

    apply(zoom) {
        if (!this.hasPageTarget) {
            return;
        }
        this.zoom = Math.min(MAX, Math.max(MIN, zoom));
        this.pageTarget.style.width = `${this.zoom}%`;
        this.labelTarget.textContent = `${this.zoom} %`;
    }
}
