import { Controller } from '@hotwired/stimulus';

/*
 * On the scrolling .px-main: once the page has scrolled, the sticky page head takes its shadow
 * (design-ui.md § 4.3, « collé, il prend --px-shadow-sticky »).
 */
export default class extends Controller {
    static targets = ['head'];

    connect() {
        this.onScroll = this.update.bind(this);
        this.element.addEventListener('scroll', this.onScroll, { passive: true });
        this.update();
    }

    disconnect() {
        this.element.removeEventListener('scroll', this.onScroll);
    }

    update() {
        if (this.hasHeadTarget) {
            this.headTarget.classList.toggle('is-stuck', this.element.scrollTop > 0);
        }
    }
}
