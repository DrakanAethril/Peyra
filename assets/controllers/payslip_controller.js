import { Controller } from '@hotwired/stimulus';

/*
 * The payslip opened one employee at a time (steps 4.4a and 4.4b; design-ux.md § 4.7):
 * - a click on a line, or F5 on the line in focus, shows how it was calculated in the drawer
 *   (each line carries its explanation in a <template>);
 * - the lines are a grid: Entrée in a cell goes to the next open cell (Tab visits them too), F9 / F10
 *   post the payslip - the elements of the month and the cells typed - which calculates it again;
 * - Ctrl+Suppr on a line removes its rubrique when it may be removed (the post says why not
 *   otherwise); Inser opens the list of the rubriques to add (a button of the head);
 * - what was typed and not saved holds the way to another employee (Ctrl ↑ / Ctrl ↓, the list) and
 *   the validation: F10 first, as the status line says.
 */
export default class extends Controller {
    static targets = ['line', 'detail', 'drawer', 'unsaved', 'cell', 'form'];

    connect() {
        this.dirty = false;
        this.current = null;
        this.onLeave = this.onLeave.bind(this);
        // The head's buttons (previous, next, detail, validate) sit outside this element: listen on the document.
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
        if (event.target instanceof Element && event.target.closest('input')) {
            return;
        }
        this.show(event.currentTarget);
    }

    lineKey(event) {
        const plain = !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey;
        if (event.key === 'Enter' && plain) {
            event.preventDefault();
            if (event.target instanceof HTMLInputElement) {
                this.nextCell(event.target);
            } else {
                this.show(event.currentTarget);
            }

            return;
        }
        if (event.key === 'Delete' && (event.ctrlKey || event.metaKey) && !event.altKey) {
            event.preventDefault();
            event.stopPropagation();
            this.remove(event.currentTarget);
        }
    }

    nextCell(cell) {
        const cells = this.cellTargets;
        const next = cells[cells.indexOf(cell) + 1];
        if (next) {
            next.focus();
            next.select();
        }
    }

    remove(line) {
        if (!this.hasFormTarget || line.dataset.removable !== '1') {
            return;
        }
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'remove';
        field.value = line.dataset.code;
        this.formTarget.append(field);
        this.dirty = false;
        this.formTarget.requestSubmit();
    }

    explainCurrent() {
        const focused = document.activeElement instanceof Element ? document.activeElement.closest('tr') : null;
        const line = this.lineTargets.includes(focused) ? focused : (this.current ?? this.lineTargets[0]);
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
