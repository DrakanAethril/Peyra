# Peyra

**Peyra** est un PGI pédagogique pour le **BTS Comptabilité et gestion** : un progiciel de gestion
intégré pensé pour la classe. Côté étudiant, il a les codes d'un logiciel professionnel — un
dossier de société, des journaux, une saisie entièrement au clavier, des pièces, le rapprochement
bancaire, les déclarations. Côté enseignant, il fait ce qu'aucun logiciel du marché ne fait :
distribuer un dossier à une classe, générer les pièces, suivre la classe en direct, corriger par
comparaison avec un corrigé, suivre les activités du référentiel et préremplir le passeport
professionnel. Le référentiel du BTS CG, processus P1 à P7, est la référence.

Les sociétés, les salariés et les données de démonstration sont fictifs. Peyra ne communique avec
aucun service réel : les portails fiscaux, sociaux et de facturation électronique sont simulés dans
l'application.

## État du projet

**En construction**, lot par lot. Rien n'est encore utilisable en classe. Peyra est développé pour
l'Institution Beaupeyrat, qui l'utilisera ; l'application ne lui appartient pas (voir Licence).

## Démarrer en développement

Il faut Docker (avec Compose v2). Tout s'exécute dans les conteneurs : aucun PHP n'est nécessaire
sur la machine.

```console
cp .env.dev.local.example .env.dev.local   # une seule fois : valeurs locales, toutes fictives
docker compose build --pull
docker compose up --wait
docker compose exec php bin/console app:dev:school      # une seule fois : années et classes de démonstration
docker compose exec php bin/console app:regulatory:sync # les millésimes réglementaires du dépôt (config/regulatory)
docker compose exec php bin/console app:demo:install    # le dossier de démonstration, Menuiserie Vasseur SARL
docker compose exec php bin/console app:dev:dossiers    # après la première connexion des étudiants : leur dossier
```

L'application répond alors sur <https://localhost:8143> (certificat local à accepter) ;
<https://localhost:8143/health> dit si elle tourne. Les ports (8180, 8143) et le nom du projet
Compose (`peyra`) sont réglés dans `.env`, pour cohabiter avec d'autres applications sur la même
machine.

```console
docker compose exec php bin/console about              # la console Symfony
docker compose exec -e APP_ENV=test php bin/phpunit    # les tests (base de test : tests/README.md)
docker compose exec php composer cs-check              # la norme de code
docker compose exec php composer phpstan               # l'analyse statique
docker compose --profile worker up -d worker           # les tâches planifiées (facultatif en dev)
docker compose down --remove-orphans                   # arrêter
```

La galerie du système de design, <https://localhost:8143/_design> (développement seulement), montre
chaque composant de l'interface dans chacun de ses états, dans la coquille de l'étudiant et dans
celle de l'enseignant ; `bin/shot <adresse> <fichier.png>` en fait une capture avec Chrome sans
écran.

On s'y connecte avec un compte de l'annuaire fictif, mot de passe `peyra-dev` : `prof.martin`
(enseignant, administrateur), `prof.durand` (enseignante), `ines.morel` (étudiante de 1re année),
`yasmine.haddad` (étudiante de 2e année) ; `enzo.petit` et `prof.leroy` montrent les refus. La
liste complète est dans `CLAUDE.md`. Seuls les étudiants et les enseignants du BTS CG entrent ;
aucun mot de passe n'est conservé par Peyra. `app:dev:school` crée les années 2025-2026 et
2026-2027 (courante), les classes « BTS CG 1 » et « BTS CG 2 » et leur correspondance avec les
groupes `cg-1` et `cg-2` : un étudiant est placé dans sa classe à sa première connexion.
`app:demo:install` installe Menuiserie Vasseur SARL comme dossier modèle de la bibliothèque commune,
avec son plan de comptes (le PCG 2026 de l'ANC, complété de ses comptes propres), ses six
journaux et ses 59 tiers (clients, fournisseurs et autres, chacun sur son compte collectif avec son
code auxiliaire, ou sur son compte), sa balance d'ouverture du 1er janvier 2026 (une écriture
validée du journal des à-nouveaux équilibrée à 459 260,31), les achats et la Banque Populaire de
janvier et ses sept modèles d'écriture (loyer, crédit-bail, honoraires, achat et vente à 20 %, frais
bancaires, règlement fournisseur), ses deux abonnements (le loyer et le crédit-bail, à partir de
février), datée du 31 janvier 2026 ; `app:dev:dossiers` en donne une copie à chaque étudiant placé
(en attendant la distribution), qu'il ouvre depuis « Mes dossiers » et que les enseignants de sa classe ouvrent
depuis sa fiche. Comptabilité › Saisie y est la grille de saisie au clavier, par journal et par
mois, à la manière de Sage : F6 y appelle un modèle d'écriture (HT, TVA et TTC calculés du montant
tapé), la saisie guidée part d'un modèle et de quelques champs, et un abonnement appelle un modèle
chaque mois (« Générer jusqu'au » la date du dossier, au brouillard ou en saisie en attente). Les plans comptables de référence (PCG 2026, PCG 2025, PCG antérieur), lus dans
les textes de l'ANC, sont dans `fixtures/pcg/`.

La pile de développement : `php` (FrankenPHP et Caddy), `database` (MariaDB, comme en
production), `openldap` (un annuaire fictif, versionné dans `frankenphp/ldap/`), `gotenberg`
(rendu des PDF) et, à la demande, `worker` (Symfony Scheduler).

## En production

Peyra se déploie avec `compose.yaml` et `compose.prod.yaml`, derrière un reverse proxy qui termine
TLS. `docs/production.md` décrit les prérequis, les fichiers propres au serveur (dont un gabarit
versionné, `.env.prod.local.example`), le premier déploiement, la mise à jour, le retour en arrière,
la sauvegarde et les diagnostics (`/health`, `bin/console app:ldap:check`). Les fichiers des dossiers
vont dans un compartiment S3 privé que crée la configuration Terraform de `infra/terraform/`, lancée
par l'exploitant depuis sa machine avec son profil AWS (même document).

## Licence

Peyra est un logiciel libre, sous licence **GNU Affero General Public License v3.0 ou
ultérieure** (`LICENSE`), copyright Sébastien Tharaud. Les noms, logos et emblèmes de
l'Institution Beaupeyrat sont exclus de la licence (`NOTICE`).

Code source : <https://github.com/DrakanAethril/Peyra>.
