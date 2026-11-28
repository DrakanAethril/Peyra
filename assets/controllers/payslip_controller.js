import { Controller } from '@hotwired/stimulus';

/*
 * The payslip opened one employee at a time (step 4.4a; design-ux.md § 4.7):
 * - a click on a line, or F5 on the line in focus, shows how it was calculated in the drawer
 *   (each line carries its explanation in a <template>);
 * - the elements of the month changed and not saved hold the way to another employee
 *   (Ctrl ↑ / Ctrl ↓, the list): F10 first, as the status line says.
 */
export default class extends Controller {
    static targets = ['line', 'detail', 'drawer', 'unsaved'];

    connect() {
        this.dirty = false;
        this.current = null;
        this.onLeave = this.onLeave.bind(this);
        // The head's buttons (previous, next, detail) sit outside this element: listen on the document.
        document.addEventListener('click', this.onLeave, true);
    }

    disconnect() {
        document.removeEventListener('click', this.onLeave, true);
    }

    changed() {
        this.dirty = true;
    }

    saving() {
        this.dirty = false;
    }

    focusLine(event) {
        this.current = event.currentTarget;
    }

    explain(event) {
        this.show(event.currentTarget);
    }

    lineKey(event) {
        if (event.key === 'Enter' && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            this.show(event.currentTarget);
        }
    }

    explainCurrent() {
        const line = this.lineTargets.includes(document.activeElement) ? document.activeElement : (this.current ?? this.lineTargets[0]);
        if (line) {
            this.show(line);
        }
    }

    show(line) {
        const template = line.querySelector('template');
        if (!template || !this.hasDetailTarget || !this.hasDrawerTarget) {
            return;
        }
        this.current = line;
        this.detailTarget.replaceChildren(template.content.cloneNode(true));
        this.drawerTarget.dispatchEvent(new CustomEvent('px:open', { detail: { opener: line } }));
    }

    onLeave(event) {
        if (!(event.target instanceof Element)) {
            return;
        }
        if (event.target.closest('[data-payslip-explain]')) {
            event.preventDefault();
            this.explainCurrent();

            return;
        }
        if (!this.dirty) {
            return;
        }
        const link = event.target.closest('[data-payslip-leave]');
        if (!link) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (this.hasUnsavedTarget) {
            this.unsavedTarget.hidden = false;
            this.unsavedTarget.focus?.();
        }
    }
}
