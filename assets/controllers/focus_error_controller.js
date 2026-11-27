import { Controller } from '@hotwired/stimulus';

/*
 * A form sent back refused (422) puts the focus on its first field in error, in the order of the
 * page (design-ux.md § 6.1, « le focus va au premier champ en erreur »), after Turbo has applied
 * the autofocus of the page's first field.
 */
export default class extends Controller {
    connect() {
        // After Turbo has rendered the page and applied its own autofocus.
        requestAnimationFrame(() => {
            const field = this.element.querySelector('[aria-invalid="true"]');
            field?.focus();
        });
    }
}
