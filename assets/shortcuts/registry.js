/*
 * The central registry of keyboard shortcuts (design-ux.md § 5). One canonical name per combination
 * ("Ctrl+Enter", "Alt+1", "F10", "?"), the same name the server writes in data-px-shortcut and that
 * App\Twig\Shortcut turns into a French key label. Global shortcuts are listed here; a screen adds
 * its own by marking the element that answers them: <button data-px-shortcut="F10">.
 */

// On macOS, Cmd stands for Ctrl on these letters only (design-ux.md § 5.1).
const MAC_COMMAND_LETTERS = new Set(['K', 'S', 'B', 'Z', 'A', 'D']);

// The browser's own keys Peyra never takes (design-ux.md § 5.1).
const NEVER = new Set(['F11', 'F12', 'Ctrl+N', 'Ctrl+T', 'Ctrl+W', 'Alt+ArrowLeft', 'Alt+ArrowRight', 'Alt+D', 'F3']);

const KEY_NAMES = {
    ' ': 'Space',
    Esc: 'Escape',
    Del: 'Delete',
    Left: 'ArrowLeft',
    Right: 'ArrowRight',
    Up: 'ArrowUp',
    Down: 'ArrowDown',
};

/** Shortcuts every screen answers, in the order the shortcut sheet lists them. */
export const GLOBAL_SHORTCUTS = [
    { combo: 'Ctrl+K', label: 'Recherche globale' },
    { combo: 'Alt+1', label: 'Entrées du menu, dans l’ordre (Alt 1 à Alt 0)' },
    { combo: 'Ctrl+B', label: 'Replier ou déplier le menu' },
    { combo: 'Ctrl+Alt+ArrowLeft', label: 'Onglet précédent du module' },
    { combo: 'Ctrl+Alt+ArrowRight', label: 'Onglet suivant du module' },
    { combo: '?', label: 'Raccourcis de cet écran (aussi F1)' },
    { combo: 'Escape', label: 'Fermer le panneau ouvert' },
    { combo: 'F10', label: 'Enregistrer (aussi Ctrl S)' },
    { combo: 'Ctrl+Enter', label: 'Valider, exécuter, déposer' },
];

/** Aliases: a combination that triggers another one's element. */
export const ALIASES = { 'Ctrl+S': 'F10', F1: '?' };

/** Combinations that only count outside a text field (a typed character must stay a character). */
export const OUTSIDE_FIELDS = new Set(['?', '/', 'N', '1', '2', '3', '4']);

/** The canonical name of a keydown event, or null for a key alone that names nothing. */
export function comboOf(event) {
    let key = KEY_NAMES[event.key] ?? event.key;
    if (['Control', 'Shift', 'Alt', 'Meta', 'AltGraph'].includes(key)) {
        return null;
    }
    // With Alt (and on macOS Option), event.key is a composed character: read the physical key.
    if (event.altKey && /^Digit[0-9]$/.test(event.code)) {
        key = event.code.slice(5);
    } else if (event.altKey && /^Key[A-Z]$/.test(event.code)) {
        key = event.code.slice(3);
    }
    if (key.length === 1) {
        key = key.toUpperCase();
    }

    const letterIsCommand = event.metaKey && MAC_COMMAND_LETTERS.has(key);
    const parts = [];
    if (event.ctrlKey || letterIsCommand || (event.metaKey && key === 'Enter')) {
        parts.push('Ctrl');
    }
    if (event.altKey) {
        parts.push('Alt');
    }
    // Shift is part of the name only for keys whose character it does not already change.
    if (event.shiftKey && key.length > 1) {
        parts.push('Shift');
    }
    parts.push(key);

    return parts.join('+');
}

export function isNever(combo) {
    return NEVER.has(combo);
}

export function isTextField(element) {
    if (!(element instanceof HTMLElement)) {
        return false;
    }
    if (element.isContentEditable) {
        return true;
    }
    if (element instanceof HTMLTextAreaElement || element instanceof HTMLSelectElement) {
        return true;
    }
    if (element instanceof HTMLInputElement) {
        return !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file'].includes(element.type);
    }

    return false;
}

/** French label of a combination, as on screen: "Ctrl ↵", "Échap", "Alt 1". Mirrors App\Twig\Shortcut. */
export function labelOf(combo) {
    const names = {
        Ctrl: 'Ctrl',
        Alt: 'Alt',
        Shift: 'Maj',
        Enter: '↵',
        Escape: 'Échap',
        Space: 'Espace',
        Delete: 'Suppr',
        Insert: 'Inser',
        Tab: 'Tab',
        ArrowUp: '↑',
        ArrowDown: '↓',
        ArrowLeft: '←',
        ArrowRight: '→',
        PageUp: 'Page préc.',
        PageDown: 'Page suiv.',
        Home: 'Début',
        End: 'Fin',
    };
    const parts = combo.split('+');
    if (parts.length === 1 && parts[0] === 'Enter') {
        return 'Entrée';
    }

    return parts.map((part) => names[part] ?? part).join(' ');
}
