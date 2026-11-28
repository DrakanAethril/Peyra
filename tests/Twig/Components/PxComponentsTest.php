<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;
use App\Service\Shared\Money;
use App\Twig\Components\Px\Avatar;
use App\Twig\Components\Px\Pager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Twig\Error\RuntimeError;

/**
 * Pins the markup of each Px component and the rules they hold (design-ui.md § 4): a screen uses
 * the component, never the raw classes, so a change here is a change on every screen.
 */
final class PxComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testAccountPickerIsAComboboxThatSaysWhichAccountIsChosen(): void
    {
        $html = $this->renderTwigComponent('Px:AccountPicker', ['id' => 'counterpart', 'url' => '/d/1/accounting/accounts/search', 'nature' => 'bank,cash', 'value' => '512100', 'accountLabel' => 'Crédit Coopératif', 'error' => 'Choisissez un compte.'])->toString();

        self::assertStringContainsString('data-controller="account-picker" data-account-picker-url-value="/d/1/accounting/accounts/search" data-account-picker-nature-value="bank,cash"', $html);
        self::assertStringContainsString('<input type="text" id="counterpart" name="counterpart" value="512100" class="px-input px-u-code" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="counterpart_list"', $html);
        self::assertStringContainsString('aria-describedby="counterpart_error counterpart_account" aria-invalid="true"', $html);
        self::assertStringContainsString('<ul class="px-picker__list" id="counterpart_list" role="listbox" aria-label="Comptes"', $html);
        self::assertStringContainsString('<p class="px-picker__chosen" id="counterpart_account" data-account-picker-target="chosen" aria-live="polite">Crédit Coopératif</p>', $html);
    }

    public function testCommandPaletteIsATriggerAndAModalSearchOverAFrame(): void
    {
        $html = $this->renderTwigComponent('Px:CommandPalette', ['src' => '/d/1/search'])->toString();

        self::assertStringContainsString('<button type="button" class="px-cmdk-trigger" aria-haspopup="dialog" data-px-open="px-cmdk" data-px-shortcut="Ctrl+K" data-px-shortcut-label="Recherche globale">', $html);
        self::assertStringContainsString('<kbd class="px-kbd">Ctrl K</kbd>', $html);
        self::assertStringContainsString('<dialog id="px-cmdk" class="px-cmdk" aria-label="Recherche globale" data-controller="command-palette" data-command-palette-src-value="/d/1/search"', $html);
        self::assertStringContainsString('role="combobox" aria-expanded="true" aria-controls="px-cmdk-list" aria-autocomplete="list"', $html);
        self::assertStringContainsString('<turbo-frame id="px-cmdk-results" class="px-cmdk__results" target="_top"', $html);
        self::assertStringContainsString('dans un nouvel onglet', $html);
    }

    public function testButtonIsAButtonThatNeverSubmitsByAccident(): void
    {
        $html = $this->renderTwigComponent('Px:Button', [], 'Enregistrer')->toString();

        self::assertStringContainsString('<button type="button"', $html);
        self::assertStringContainsString('class="px-btn"', $html);
        self::assertStringContainsString('<span>Enregistrer</span>', $html);
    }

    public function testButtonWithAnHrefIsALinkAndShowsItsKey(): void
    {
        $html = $this->renderTwigComponent('Px:Button', ['variant' => 'primary', 'href' => '/x', 'shortcut' => 'F10 Ctrl+S', 'icon' => 'save'], 'Enregistrer')->toString();

        self::assertStringContainsString('<a href="/x"', $html);
        self::assertStringContainsString('class="px-btn px-btn--primary"', $html);
        self::assertStringContainsString('data-px-shortcut="F10 Ctrl+S"', $html);
        self::assertStringContainsString('<kbd class="px-kbd">F10</kbd>', $html);
        self::assertStringContainsString('<svg', $html);
    }

    public function testADisabledButtonSaysWhy(): void
    {
        $html = $this->renderTwigComponent('Px:Button', ['variant' => 'primary', 'disabled' => true, 'id' => 'save', 'disabledReason' => 'L’écriture n’est pas équilibrée.'], 'Enregistrer')->toString();

        self::assertStringContainsString(' disabled', $html);
        self::assertStringContainsString('aria-describedby="save-reason"', $html);
        self::assertStringContainsString('<span id="save-reason" class="px-u-sr-only">L’écriture n’est pas équilibrée.</span>', $html);
    }

    public function testButtonStatesAreAttributes(): void
    {
        $html = $this->renderTwigComponent('Px:Button', ['pressed' => true, 'busy' => true, 'size' => 'sm'], 'Afficher')->toString();

        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('aria-busy="true"', $html);
        self::assertStringContainsString('px-btn--sm', $html);
    }

    public function testButtonRefusesAVariantTheStylesheetDoesNotDraw(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/no "outline" variant/');
        $this->renderTwigComponent('Px:Button', ['variant' => 'outline'], 'x');
    }

    public function testIconButtonRefusesToHaveNoName(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/needs a label/');
        $this->renderTwigComponent('Px:IconButton', ['icon' => 'x', 'label' => '']);
    }

    public function testIconButtonCarriesItsNameAndTheUnreadDot(): void
    {
        $html = $this->renderTwigComponent('Px:IconButton', ['icon' => 'bell', 'label' => 'Notifications', 'dot' => true, 'dotLabel' => 'du nouveau'])->toString();

        self::assertStringContainsString('aria-label="Notifications, du nouveau"', $html);
        self::assertStringContainsString('title="Notifications"', $html);
        self::assertStringContainsString('class="px-iconbtn__dot"', $html);
    }

    public function testBadgeReadsTextAndToneFromAnEnum(): void
    {
        $html = $this->renderTwigComponent('Px:Badge', ['of' => GalleryState::Validated])->toString();

        self::assertSame('<span class="px-badge px-badge--success px-badge--dot">Validée</span>', trim($html));
    }

    public function testBadgeWithoutDotForANatureAndFullWordingForAnAbbreviation(): void
    {
        $nature = $this->renderTwigComponent('Px:Badge', ['tone' => 'info', 'dot' => false, 'label' => 'Factur-X'])->toString();
        self::assertSame('<span class="px-badge px-badge--info">Factur-X</span>', trim($nature));

        $short = $this->renderTwigComponent('Px:Badge', ['tone' => BadgeTone::Success, 'label' => 'A', 'full' => 'Pointé, lettre A'])->toString();
        self::assertStringContainsString('title="Pointé, lettre A"', $short);
        self::assertStringContainsString('<span aria-hidden="true">A</span><span class="px-u-sr-only">Pointé, lettre A</span>', $short);
    }

    public function testBadgeRefusesAToneOutsideTheEnum(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/no "green" tone/');
        $this->renderTwigComponent('Px:Badge', ['tone' => 'green', 'label' => 'x']);
    }

    public function testTrailNeverStartsWithAccueil(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/never starts with « Accueil »/');
        $this->renderTwigComponent('Px:Trail', ['segments' => [['label' => 'Accueil', 'href' => '/'], ['label' => 'Saisie', 'href' => '/s']]]);
    }

    public function testTrailMarksTheCurrentPageLast(): void
    {
        $html = $this->renderTwigComponent('Px:Trail', ['segments' => [['label' => 'Comptabilité', 'href' => '/c'], ['label' => 'Saisie', 'href' => '/s']]])->toString();

        self::assertStringContainsString('<li><a href="/c">Comptabilité</a></li>', $html);
        self::assertStringContainsString('<li><a href="/s" aria-current="page">Saisie</a></li>', $html);
        self::assertStringNotContainsString('›', $html, 'The separator is drawn by the stylesheet, never written.');
    }

    public function testContextBarIsVioletInAStudentsDossierAndGoldInATemplate(): void
    {
        $student = $this->renderTwigComponent('Px:ContextBar', ['backLabel' => 'Revenir', 'backHref' => '/teacher'], 'Dossier de Manon Chevalier')->toString();
        $template = $this->renderTwigComponent('Px:ContextBar', ['backLabel' => 'Revenir', 'backHref' => '/teacher', 'tone' => 'template'], 'Dossier modèle')->toString();

        self::assertStringContainsString('class="px-contextbar" role="note"', $student);
        self::assertStringContainsString('<a href="/teacher">Revenir</a>', $student);
        self::assertStringContainsString('class="px-contextbar px-contextbar--template"', $template);
    }

    public function testAvatarInitials(): void
    {
        self::assertSame('MC', Avatar::initialsOf('Manon Chevalier'));
        self::assertSame('JT', Avatar::initialsOf('Jean-Pierre de la Tour'));
        self::assertSame('T', Avatar::initialsOf('  Théo '));
        self::assertSame('ÉR', Avatar::initialsOf('élodie roux'));
        self::assertSame('', Avatar::initialsOf(''));

        $html = $this->renderTwigComponent('Px:Avatar', ['name' => 'Inès Moreau', 'me' => true])->toString();
        self::assertSame('<span class="px-avatar px-avatar--me" aria-hidden="true">IM</span>', trim($html));
    }

    public function testPagerWindow(): void
    {
        self::assertSame([1, 2, 3, 4, 5], Pager::windowOf(3, 5));
        self::assertSame([1, 2, 3, 4, 5, 6, 7], Pager::windowOf(7, 7));
        self::assertSame([1, null, 4, 5, 6, null, 12], Pager::windowOf(5, 12));
        self::assertSame([1, 2, null, 12], Pager::windowOf(1, 12));
        self::assertSame([1, 2, 3, 4, null, 12], Pager::windowOf(3, 12), 'A gap hiding a single page shows the page.');
        self::assertSame([1, null, 11, 12], Pager::windowOf(12, 12));
        self::assertSame([1], Pager::windowOf(1, 1));
    }

    public function testPagerCountsAndKeepsTheQuery(): void
    {
        $request = Request::create('/_design', 'GET', ['journal' => 'ACH', 'page' => '2']);
        $request->attributes->set('_route', 'app_design_gallery');
        $request->attributes->set('_route_params', []);
        self::getContainer()->get(RequestStack::class)->push($request);

        $pager = $this->mountTwigComponent('Px:Pager', ['total' => 431, 'perPage' => 50, 'page' => 2]);
        self::assertInstanceOf(Pager::class, $pager);
        self::assertSame(51, $pager->first());
        self::assertSame(100, $pager->last());
        self::assertSame(9, $pager->pageCount());
        self::assertSame('/_design?journal=ACH&page=3', $pager->url(3));

        $html = $this->renderTwigComponent('Px:Pager', ['total' => 431, 'perPage' => 50, 'page' => 2])->toString();
        self::assertStringContainsString('51 à 100 sur 431', $html);
        self::assertStringContainsString('aria-current="page" aria-label="Page 2"', $html);
    }

    public function testPagerOfAnEmptyList(): void
    {
        $html = $this->renderTwigComponent('Px:Pager', ['total' => 0])->toString();

        self::assertStringContainsString('Aucune ligne', $html);
        self::assertStringNotContainsString('px-pager__pages', $html);
    }

    public function testPagerSizesSetPerAndGoBackToTheFirstPage(): void
    {
        $request = Request::create('/_design', 'GET', ['journal' => 'ACH', 'page' => '3']);
        $request->attributes->set('_route', 'app_design_gallery');
        $request->attributes->set('_route_params', []);
        self::getContainer()->get(RequestStack::class)->push($request);

        $html = $this->renderTwigComponent('Px:Pager', ['total' => 431, 'perPage' => 100, 'page' => 3, 'sizes' => true])->toString();

        self::assertStringContainsString('aria-label="Lignes par page"', $html);
        self::assertStringContainsString('href="/_design?journal=ACH&amp;page=1&amp;per=25"', $html);
        self::assertStringContainsString('aria-current="true" aria-label="100 lignes par page"', $html);
        self::assertStringNotContainsString('aria-current="true" aria-label="50 lignes par page"', $html);
    }

    public function testAmountInputShowsFrenchAndPostsCents(): void
    {
        $html = $this->renderTwigComponent('Px:AmountInput', ['id' => 'amount', 'value' => Money::cents(-262200), 'unit' => '€', 'grid' => true, 'error' => 'Montant illisible.'])->toString();

        self::assertStringContainsString('class="px-amount px-amount--grid px-amount--unit"', $html);
        self::assertStringContainsString('data-controller="amount-input"', $html);
        self::assertStringContainsString('value="'."\u{2212}\u{00A0}2\u{202F}622,00".'"', $html);
        self::assertStringContainsString('inputmode="decimal"', $html);
        self::assertStringContainsString('<input type="hidden" name="amount" value="-262200"', $html);
        self::assertStringContainsString('aria-describedby="amount_error" aria-invalid="true"', $html);
        self::assertStringContainsString('<span class="px-amount__unit" aria-hidden="true">€</span>', $html);
    }

    public function testAmountInputLeftEmptyPostsNothing(): void
    {
        $html = $this->renderTwigComponent('Px:AmountInput', ['id' => 'a', 'name' => 'line[debit]'])->toString();

        self::assertStringContainsString('<input type="hidden" name="line[debit]" value=""', $html);
        self::assertStringContainsString('value=""', $html);
    }

    public function testAmountInputRefusesAnUnknownUnit(): void
    {
        $this->expectException(RuntimeError::class);
        $this->renderTwigComponent('Px:AmountInput', ['id' => 'a', 'unit' => '$']);
    }

    public function testDateInputIsNeverANativeDateField(): void
    {
        $html = $this->renderTwigComponent('Px:DateInput', [
            'id' => 'date',
            'reference' => new \DateTimeImmutable('2026-11-30'),
            'value' => new \DateTimeImmutable('2026-11-12'),
            'hint' => true,
        ])->toString();

        self::assertStringNotContainsString('type="date"', $html);
        self::assertStringContainsString('data-date-input-reference-value="2026-11-30"', $html);
        self::assertStringContainsString('value="12/11/2026"', $html);
        self::assertStringContainsString('placeholder="JJ/MM/AAAA"', $html);
        self::assertStringContainsString('<input type="hidden" name="date" value="2026-11-12"', $html);
        self::assertStringContainsString('aria-controls="date_calendar"', $html);
        self::assertStringContainsString('aria-describedby="date_hint"', $html);
    }

    public function testSortHeaderReversesTheSortingColumnAndResetsThePage(): void
    {
        $request = Request::create('/_design', 'GET', ['sort' => 'date', 'dir' => 'asc', 'page' => '4']);
        $request->attributes->set('_route', 'app_design_gallery');
        $request->attributes->set('_route_params', []);
        self::getContainer()->get(RequestStack::class)->push($request);

        $sorted = $this->renderTwigComponent('Px:SortHeader', ['label' => 'Date', 'field' => 'date', 'sort' => 'date', 'direction' => 'asc'])->toString();
        self::assertStringContainsString('aria-sort="ascending"', $sorted);
        self::assertStringContainsString('href="/_design?sort=date&amp;dir=desc"', $sorted);

        $other = $this->renderTwigComponent('Px:SortHeader', ['label' => 'Compte', 'field' => 'account', 'sort' => 'date', 'direction' => 'asc', 'right' => true])->toString();
        self::assertStringNotContainsString('aria-sort', $other);
        self::assertStringContainsString('href="/_design?sort=account&amp;dir=asc"', $other);
        self::assertStringContainsString('class="px-u-right"', $other);
    }

    public function testAlertRoleFollowsItsTone(): void
    {
        $danger = $this->renderTwigComponent('Px:Alert', ['tone' => 'danger', 'title' => 'Le formulaire contient une erreur.'], 'Indiquez le compte.')->toString();
        self::assertStringContainsString('class="px-alert px-alert--danger" role="alert"', $danger);
        self::assertStringContainsString('<p class="px-alert__title">Le formulaire contient une erreur.</p>', $danger);

        $info = $this->renderTwigComponent('Px:Alert', [], 'La date du dossier a été avancée.')->toString();
        self::assertStringContainsString('role="status"', $info);
    }

    public function testKpiColoursAValueForACheckOnly(): void
    {
        $html = $this->renderTwigComponent('Px:Kpi', ['label' => 'Écart', 'value' => '0,00', 'state' => 'ok', 'href' => '/list'])->toString();
        self::assertStringContainsString('<a class="px-kpi" href="/list">', $html);
        self::assertStringContainsString('px-kpi__value--ok', $html);

        $this->expectException(RuntimeError::class);
        $this->renderTwigComponent('Px:Kpi', ['label' => 'Résultat', 'value' => '12', 'state' => 'positive']);
    }

    public function testConfirmButtonCarriesBothLabelsAndTheController(): void
    {
        $html = $this->renderTwigComponent('Px:ConfirmButton', ['label' => 'Supprimer le brouillon', 'confirmLabel' => 'Confirmer la suppression', 'data-action' => 'dialog#close'])->toString();

        self::assertStringContainsString('data-controller="confirm"', $html);
        self::assertStringContainsString('<span class="px-confirm__rest">Supprimer le brouillon</span><span class="px-confirm__armed">Confirmer la suppression</span>', $html);
        self::assertStringContainsString('data-action="confirm#click dialog#close"', $html, 'The two-step click runs first, then the caller’s action.');
        self::assertStringContainsString('type="submit"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
    }

    public function testRailItemRefusesACountItDoesNotName(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/without saying what it counts/');
        $this->renderTwigComponent('Px:RailItem', ['href' => '#', 'icon' => 'inbox', 'label' => 'Pièces', 'count' => 3]);
    }

    public function testRailItem(): void
    {
        $html = $this->renderTwigComponent('Px:RailItem', ['href' => '/p', 'icon' => 'inbox', 'label' => 'Pièces', 'count' => 3, 'countLabel' => '3 pièces à traiter', 'current' => true])->toString();

        self::assertStringContainsString('data-tooltip="Pièces" data-px-rail-entry aria-current="page"', $html);
        self::assertStringContainsString('<span class="px-rail__count" aria-label="3 pièces à traiter"><span aria-hidden="true">3</span></span>', $html);
    }

    public function testChipAndKbd(): void
    {
        self::assertStringContainsString('class="px-chip px-chip--role"', $this->renderTwigComponent('Px:Chip', ['tone' => 'role'], 'Espace enseignant')->toString());
        self::assertSame('<kbd class="px-kbd">Ctrl ↵</kbd>', trim($this->renderTwigComponent('Px:Kbd', ['combo' => 'Ctrl+Enter'])->toString()));

        $this->expectException(RuntimeError::class);
        $this->renderTwigComponent('Px:Chip', ['tone' => 'success'], 'x');
    }

    public function testFieldAndInputAreLinkedByIdHintAndError(): void
    {
        $html = $this->renderTwigComponent('Px:Field', ['id' => 'date', 'label' => 'Date', 'required' => true, 'error' => 'La période de novembre 2026 est close.'], '<input id="date">')->toString();
        self::assertStringContainsString('<label class="px-field__label" for="date">Date <span class="px-field__required" aria-hidden="true">*</span><span class="px-u-sr-only"> (obligatoire)</span></label>', $html);
        self::assertStringContainsString('<p class="px-field__error" id="date_error">', $html);

        $input = $this->renderTwigComponent('Px:Input', ['id' => 'date', 'value' => '12/11/2026', 'error' => true, 'hint' => true])->toString();
        self::assertStringContainsString('aria-describedby="date_hint date_error"', $input);
        self::assertStringContainsString('aria-invalid="true"', $input);
        self::assertStringContainsString('class="px-input"', $input);
    }

    public function testSelectForInputStartsOnItsPrompt(): void
    {
        $html = $this->renderTwigComponent('Px:Select', ['id' => 'journal', 'placeholder' => 'Choisir un journal…', 'options' => [['value' => 'ACH', 'label' => 'Achats']]])->toString();

        self::assertStringContainsString('<option value="" selected>Choisir un journal…</option>', $html);
        self::assertStringContainsString('<option value="ACH">Achats</option>', $html);
    }

    public function testCheckGroupIsAFieldsetNeverASelectMultiple(): void
    {
        $html = $this->renderTwigComponent('Px:CheckGroup', ['legend' => 'Modules', 'name' => 'modules', 'options' => [['value' => 'sales', 'label' => 'Ventes', 'checked' => true], ['value' => 'bank', 'label' => 'Banque']]])->toString();

        self::assertStringContainsString('<fieldset class="px-checkgroup">', $html);
        self::assertStringContainsString('<input type="checkbox" name="modules[]" value="sales" checked>', $html);
        self::assertStringNotContainsString('<select', $html);
    }

    public function testSegmentedIsARadioGroup(): void
    {
        $html = $this->renderTwigComponent('Px:Segmented', ['legend' => 'Positionnement', 'name' => 'level', 'value' => 2, 'hint' => 'Proposé par l’outil.', 'options' => [['value' => 1, 'label' => 'Non acquis'], ['value' => 2, 'label' => 'En cours']]])->toString();

        self::assertStringContainsString('<input type="radio" name="level" value="2" checked>', $html);
        self::assertStringContainsString('aria-describedby="level_hint"', $html);
    }

    public function testTableHasACaptionAndItsDensity(): void
    {
        $html = $this->renderTwigComponent('Px:Table', ['caption' => 'Écritures', 'density' => 'compact', 'rows' => true], '<tbody></tbody>')->toString();

        self::assertStringContainsString('<div class="px-table-wrap">', $html);
        self::assertStringContainsString('data-density="compact"', $html);
        self::assertStringContainsString('<caption class="px-u-sr-only">Écritures</caption>', $html);
        self::assertStringContainsString('data-controller="rows"', $html);
    }

    public function testDialogAndDrawer(): void
    {
        $dialog = $this->renderTwigComponent('Px:Dialog', ['id' => 'reverse', 'title' => 'Contrepasser', 'src' => '/fragment'])->toString();
        self::assertStringContainsString('<dialog id="reverse"', $dialog);
        self::assertStringContainsString('aria-labelledby="reverse-title"', $dialog);
        self::assertStringContainsString('data-dialog-src-value="/fragment"', $dialog);

        $drawer = $this->renderTwigComponent('Px:Drawer', ['id' => 'mission', 'title' => 'Mission'], '<p>Consigne</p>')->toString();
        self::assertStringContainsString('role="dialog" aria-modal="false"', $drawer);
        self::assertStringContainsString(' inert', $drawer);
    }

    public function testExportMenuOffersBothFormatsOutsideTurbo(): void
    {
        $menu = self::tight($this->renderTwigComponent('Px:ExportMenu', ['xlsx' => '/export.xlsx?account=401SCIE', 'csv' => '/export.csv?account=401SCIE'])->toString());

        self::assertStringContainsString('data-controller="menu"', $menu);
        self::assertStringContainsString('aria-haspopup="menu" aria-expanded="false" aria-controls="export-menu"', $menu);
        self::assertStringContainsString('<a class="px-menu__item" role="menuitem" href="/export.xlsx?account=401SCIE" data-turbo="false">', $menu);
        self::assertStringContainsString('<a class="px-menu__item" role="menuitem" href="/export.csv?account=401SCIE" data-turbo="false">', $menu);
        self::assertStringContainsString('id="export-menu" data-menu-target="panel" hidden', $menu);
    }

    public function testStepperAndTabs(): void
    {
        $steps = self::tight($this->renderTwigComponent('Px:Stepper', ['steps' => [['label' => 'Préparation', 'href' => '/1', 'state' => 'done'], ['label' => 'Cadrage', 'href' => '/2', 'state' => 'current'], ['label' => 'Dépôt', 'href' => '/3']]])->toString());
        self::assertStringContainsString('<li class="is-done"><a href="/1"><i aria-hidden="true">✓</i>Préparation<span class="px-u-sr-only"> (faite)</span></a></li>', $steps);
        self::assertStringContainsString('<li aria-current="step"><a href="/2">', $steps);
        self::assertStringContainsString('<span><i aria-hidden="true">3</i>Dépôt</span>', $steps, 'A step to come is not a link.');

        $tabs = $this->renderTwigComponent('Px:Tabs', ['tabs' => [['label' => 'Identité', 'href' => '/i', 'current' => true], ['label' => 'Échéances', 'href' => '/e', 'count' => 4]]])->toString();
        self::assertStringContainsString('<a class="px-tabs__tab" href="/i" aria-current="page">Identité', $tabs);
        self::assertStringContainsString('px-badge--neutral', $tabs);
    }

    /** Markup with the whitespace between tags removed, as a browser would not render it. */
    private static function tight(string $html): string
    {
        return (string) preg_replace('/>\s+</', '><', trim($html));
    }

    public function testEmptyAndChecks(): void
    {
        $empty = $this->renderTwigComponent('Px:Empty', ['title' => 'Aucune pièce à traiter', 'text' => 'Les prochaines arriveront ici.'])->toString();
        self::assertStringContainsString('<p class="px-empty__title">Aucune pièce à traiter</p>', $empty);

        $checks = $this->renderTwigComponent('Px:Checks', ['items' => [['state' => 'blocking', 'text' => 'Période close.']]])->toString();
        self::assertStringContainsString('<span class="px-u-sr-only">Bloquant : </span>Période close.', $checks);
    }
}

/** A HasBadge value for the test: the pairing of a state and its tone lives on the enum. */
enum GalleryState: string implements HasBadge
{
    case Validated = 'validated';

    public function label(): string
    {
        return 'Validée';
    }

    public function badgeTone(): BadgeTone
    {
        return BadgeTone::Success;
    }
}
