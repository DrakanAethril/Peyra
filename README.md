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

La pile de développement : `php` (FrankenPHP et Caddy), `database` (MariaDB, comme en
production), `openldap` (un annuaire fictif, versionné dans `frankenphp/ldap/`), `gotenberg`
(rendu des PDF) et, à la demande, `worker` (Symfony Scheduler).

## Licence

Peyra est un logiciel libre, sous licence **GNU Affero General Public License v3.0 ou
ultérieure** (`LICENSE`), copyright Sébastien Tharaud. Les noms, logos et emblèmes de
l'Institution Beaupeyrat sont exclus de la licence (`NOTICE`).

Code source : <https://github.com/DrakanAethril/Peyra>.
