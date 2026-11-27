import { Controller } from '@hotwired/stimulus';

/*
 * The module rail (design-ui.md § 4.1, B9). Folded or unfolded by its foot button or Ctrl B; the
 * choice is kept in the px_rail cookie, which the server reads to render the right state at once.
 * Under 1024 px the rail is a drawer opened from the top bar's menu button (px:open).
 */
const COOKIE = 'px_rail';
const ONE_YEAR = 60 * 60 * 24 * 365;

export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.onToggle = this.toggle.bind(this);
        this.onOpen = this.open.bind(this);
        this.onOutside = this.outside.bind(this);
        document.addEventListener('px:rail-toggle', this.onToggle);
        this.element.addEventListener('px:open', this.onOpen);
        this.syncToggle();
    }

    disconnect() {
        document.removeEventListener('px:rail-toggle', this.onToggle);
        this.element.removeEventListener('px:open', this.onOpen);
        document.removeEventListener('click', this.onOutside, true);
    }

    /** Folds an unfolded rail and the reverse, as the person sees it now. */
    toggle() {
        if (window.matchMedia('(max-width: 1279.98px)').matches) {
            return;
        }
        const collapsed = this.isCollapsed();
        this.element.classList.toggle('is-collapsed', !collapsed);
        this.element.classList.toggle('is-expanded', collapsed);
        document.cookie = `${COOKIE}=${collapsed ? 'expanded' : 'collapsed'}; path=/; max-age=${ONE_YEAR}; SameSite=Lax`;
        this.syncToggle();
    }

    isCollapsed() {
        return this.element.getBoundingClientRect().width < 100;
    }

    syncToggle() {
        if (!this.hasToggleTarget) {
            return;
        }
        const collapsed = this.isCollapsed();
        const label = collapsed ? 'Déplier le menu' : 'Replier le menu';
        this.toggleTarget.setAttribute('aria-label', label);
        this.toggleTarget.dataset.tooltip = label;
        this.toggleTarget.setAttribute('aria-expanded', String(!collapsed));
        this.toggleTarget.querySelectorAll('[data-rail-icon]').forEach((icon) => {
            icon.hidden = (icon.dataset.railIcon === 'open') !== collapsed;
        });
    }

    /** Tablet: the rail slides in over the page; Escape or a click outside closes it. */
    open(event) {
        this.opener = event.detail?.opener ?? null;
        this.element.classList.add('is-open');
        this.opener?.setAttribute('aria-expanded', 'true');
        this.element.querySelector('a, button')?.focus();
        document.addEventListener('click', this.onOutside, true);
        this.onEscape = (keyEvent) => {
            if (keyEvent.key === 'Escape') {
                this.close();
            }
        };
        document.addEventListener('keydown', this.onEscape);
    }

    close() {
        this.element.classList.remove('is-open');
        this.opener?.setAttribute('aria-expanded', 'false');
        this.opener?.focus();
        document.removeEventListener('click', this.onOutside, true);
        document.removeEventListener('keydown', this.onEscape);
    }

    outside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }
}
