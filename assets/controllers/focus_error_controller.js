import { Controller } from '@hotwired/stimulus';

/*
 * A form sent back refused (422) puts the focus on its first field in error, in the order of the
 * page (design-ux.md § 6.1, « le focus va au premier champ en erreur »), after Turbo has applied
 * the autofocus of the page's first field. A field that holds the focus by autofocus has its text
 * selected: typing replaces a proposed value (the name of a restore point), Entrée keeps it.
 */
export default class extends Controller {
    connect() {
        // After Turbo has rendered the page and applied its own autofocus.
        requestAnimationFrame(() => {
            const field = this.element.querySelector('[aria-invalid="true"]');
            if (field) {
                field.focus();

                return;
            }
            const focused = this.element.querySelector('input[autofocus]');
            if (focused instanceof HTMLInputElement && document.activeElement === focused && focused.type === 'text') {
                focused.select();
            }
        });
    }
}
