# Peyra en production

Première version (étape 0.5b du plan de réalisation) : rendre le dépôt déployable derrière le
reverse proxy de l'établissement. Les sauvegardes planifiées, les millésimes et le journal des
versions viendront compléter ce document (étape 7.6).

## Vue d'ensemble

```
navigateur ──HTTPS──▶ nginx (machine de Formatool) ──HTTP :8080──▶ machine de Peyra
                                                                   ├─ php     FrankenPHP + Caddy + hub Mercure
                                                                   ├─ worker  tâches planifiées (Symfony Scheduler)
                                                                   └─ gotenberg  rendu des PDF
php, worker ──▶ serveur MariaDB de Formatool (base pgi_mngmt)
php, worker ──▶ annuaire de l'établissement (celui de Formatool, Samba AD), en lecture
```

- **nginx termine TLS** et possède le nom public (`pgi.beaupeyrat.org` dans ces exemples). Le
  conteneur `php` ne sert qu'en HTTP, sur un seul port publié (8080), pour tout nom d'hôte :
  `compose.prod.yaml` fixe `SERVER_NAME=:80` et `CADDY_GLOBAL_OPTIONS=auto_https off`. Ne mettez
  jamais le nom de domaine dans `SERVER_NAME` : Caddy écouterait alors sur 443, même sans HTTPS
  automatique, et plus rien ne répondrait à nginx.
- **L'adresse publique** vient de `PUBLIC_URL`, exportée par `deploy-prod.sh` : elle donne les
  liens absolus (`DEFAULT_URI`) et l'adresse du hub Mercure. Sans elle, Compose refuse de démarrer.
- **Aucun service de base de données** dans la pile : Peyra utilise le serveur MariaDB de
  Formatool (11.8.6), avec sa base `pgi_mngmt` et son utilisateur `user_pgi`, qui a tous les droits
  sur cette base et aucun ailleurs. Rien dans Peyra ne crée de base ni ne donne de droit : le point
  d'entrée de `php` joue seulement les migrations, et `worker` ne migre pas.
- **Peyra n'envoie aucun courriel** et n'appelle aucun service extérieur réel.

## Prérequis

Sur la machine de Peyra :

- Linux, Docker Engine et Docker Compose **2.24 ou plus récent** (`compose.prod.yaml` utilise
  `!override`), `git`, `curl` ;
- un clone du dépôt (`git clone https://github.com/DrakanAethril/Peyra.git /srv/peyra`, par
  exemple), sur la branche `main` ;
- le pare-feu : le port **8080 ouvert à la seule adresse de la machine nginx**. Ouvert à tous, il
  permettrait de contourner nginx, et d'envoyer à Peyra des en-têtes `X-Forwarded-*` qu'il croit.

Ailleurs :

- sur le serveur MariaDB : la base `pgi_mngmt` et l'utilisateur `user_pgi` (déjà créés), joignables
  depuis la machine de Peyra ;
- dans l'annuaire : un compte de service en lecture pour Peyra, et les groupes `teacher`,
  `student`, `cg-1`, `cg-2` (ceux dont Formatool tire déjà ses rôles) ;
- sur la machine nginx : le bloc `server` ci-dessous, et un certificat pour le nom public ;
- le DNS : le nom public pointe vers nginx.

## Les fichiers du serveur

Trois fichiers vivent à la racine du clone, **jamais dans git** (`.gitignore`) ni dans l'image
(`.dockerignore`) :

| Fichier | Rôle |
|---|---|
| `deploy-prod.sh` | le déploiement, ci-dessous ; des valeurs propres à la machine, aucune secrète |
| `.env.prod.local` | les secrets et réglages, depuis le gabarit versionné `.env.prod.local.example` (droits 600) |
| `certs/ldap-ca.pem` | le certificat d'autorité de l'annuaire (LDAPS), le même que celui de Formatool |

Et un quatrième sur la machine nginx : le bloc `server`.

### `.env.prod.local`

Copiez le gabarit et remplissez chaque valeur vide :

```console
cp .env.prod.local.example .env.prod.local && chmod 600 .env.prod.local
```

Le gabarit nomme **toutes** les variables que l'application lit et que Compose ne fixe pas lui-même
(un test, `ProductionConfigurationTest`, échoue si l'application en lit une qu'il ne nomme pas).
Quelques règles :

- Compose lit ce fichier, pas Symfony : écrivez un secret **entre apostrophes** (`'…'`), sinon un
  `$`, un `#` ou une espace y sont interprétés.
- `APP_SECRET`, `MERCURE_PUBLISHER_JWT_KEY` et `MERCURE_SUBSCRIBER_JWT_KEY` : `openssl rand -hex 32`
  chacun, deux clés Mercure différentes.
- `DATABASE_URL` : dans le mot de passe, encodez `@ : / # ? %` (`%40`, `%3A`…).
- L'annuaire : les mêmes valeurs que le `.env.prod.local` de Formatool (hôte, port, chiffrement,
  base, compte de service). Les valeurs par défaut du gabarit sont celles d'un Samba AD :
  `sAMAccountName`, groupes `group`, identifiant stable `objectGUID`, LDAPS sur 636.
- `TRUSTED_PROXIES` : l'adresse de la machine nginx **telle que Peyra la voit** (voir
  « Diagnostics »), ou `REMOTE_ADDR` si le pare-feu ferme bien le port 8080 à tout autre.
- `PEYRA_ADMIN_LOGINS` : les identifiants des premiers administrateurs ; les autres se désignent
  dans Peyra (Paramètres › Administration).
- `FORMATOOL_URL` : l'adresse de Formatool, sans chemin. Elle dessine l'entrée « Formatool » du menu
  de l'avatar et le lien « Mot de passe oublié » de la connexion : Peyra ne gère aucun mot de
  passe, et la procédure est celle de Formatool (sa connexion par lien envoyé par courriel,
  `/login/magic-link`, puis le changement de mot de passe dans le profil Formatool).
  `PEYRA_PASSWORD_HELP_URL` ne sert que si la procédure est ailleurs.
- Les fichiers des dossiers (pièces, documents produits) : `PEYRA_STORAGE=s3` et les `S3_*`, tels
  que Terraform les donne (« Le compartiment S3 (Terraform) », ci-dessous) : un compartiment propre à
  Peyra, privé, et un utilisateur IAM qui n'a de droits que sur lui. `S3_ENDPOINT` reste vide (il ne
  sert que pour un service compatible S3 autre qu'AWS) ; des clés vides laisseraient le SDK chercher
  ses identifiants ailleurs (rôle d'une instance AWS), ce qui n'est pas le cas ici. À défaut de
  compartiment, `PEYRA_STORAGE=local` écrit dans le volume `app_storage` de la machine, partagé par
  `php` et `worker` : il faut alors le sauvegarder (« Sauvegarde »). Un envoi est refusé au-delà de
  20 Mo, avec une phrase, sous les 64 Mo de PHP et de nginx.

### `certs/ldap-ca.pem`

```console
mkdir -p certs && cp /chemin/vers/ldap-ca.pem certs/ldap-ca.pem
```

`compose.prod.yaml` monte `certs/` en lecture seule sur `/etc/peyra/certs` dans `php` et `worker` ;
`LDAP_TLS_CA_CERT_PATH=/etc/peyra/certs/ldap-ca.pem`. Pour un annuaire en LDAP non chiffré, laissez
la variable vide (port 389, `LDAP_ENCRYPTION=none`) : le dossier peut alors manquer.

### `deploy-prod.sh`

Il exporte ce que Compose substitue dans `compose.prod.yaml` (`PUBLIC_URL`, `HTTP_PORT`, la
version), met le clone exactement sur `origin/main`, construit l'image, démarre la pile et vérifie
`/health`. Adaptez `PUBLIC_URL` (et `HTTP_PORT` si 8080 est pris), puis `chmod 700 deploy-prod.sh`.

```bash
#!/bin/bash
# Peyra, déploiement de production. Vit sur le serveur, à la racine du checkout, jamais dans git
# (voir .gitignore) : il porte des valeurs propres à la machine, aucune secrète. Les secrets sont
# dans .env.prod.local, à côté de lui (gabarit : .env.prod.local.example). docs/production.md
# l'explique ligne à ligne.
#
# Ces variables doivent être exportées AVANT que Compose ne lise ses fichiers : il substitue les
# ${…} de compose.prod.yaml depuis l'environnement du shell, jamais depuis .env.prod.local.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

# L'adresse que les gens tapent. nginx (une autre machine, celle de Formatool) termine TLS et
# possède le nom ; le conteneur ne la déduit de rien d'autre (liens absolus, Mercure).
export PUBLIC_URL="https://pgi.beaupeyrat.org"

# Le seul port publié : HTTP, que le nginx de l'établissement atteint par
# `proxy_pass http://<cette machine>:8080`. Pare-feu : ouvert à la seule adresse de nginx, sinon on
# contourne nginx et la liste des proxys de confiance (TRUSTED_PROXIES).
# Le reste (SERVER_NAME=:80, Caddy sans HTTPS, en-têtes) est fixé par compose.prod.yaml.
export HTTP_PORT=8080

BRANCH="${DEPLOY_BRANCH:-main}"

if [ ! -f .env.prod.local ]; then
    echo "ERREUR : .env.prod.local est absent (voir .env.prod.local.example)." >&2
    exit 1
fi
# LDAPS : le certificat d'autorité de l'annuaire, monté depuis certs/ (voir docs/production.md).
if grep -q '^LDAP_TLS_CA_CERT_PATH=/etc/peyra/certs/' .env.prod.local && [ ! -f certs/ldap-ca.pem ]; then
    echo "ERREUR : LDAP_TLS_CA_CERT_PATH désigne certs/ldap-ca.pem, qui est absent." >&2
    exit 1
fi

# Le checkout du serveur ne diverge jamais de git : on jette toute dérive locale, puis on se met
# à jour. deploy-prod.sh, .env.prod.local et certs/ sont ignorés par git, ils ne sont pas touchés.
git fetch --prune origin
git checkout "$BRANCH"
git reset --hard "origin/$BRANCH"
# La version affichée dans « À propos » : le commit déployé.
PEYRA_VERSION="$(git rev-parse --short HEAD)"
export PEYRA_VERSION

COMPOSE="docker compose -f compose.yaml -f compose.prod.yaml"

$COMPOSE build --pull --no-cache
# `up --wait` attend que `php` soit sain. Son point d'entrée joue les migrations avant de démarrer ;
# `worker` ne part qu'ensuite et ne migre pas. Les arguments du script sont transmis (ex. un service).
$COMPOSE up --wait --remove-orphans "$@"

# Après un déploiement qui ajoute des valeurs réglementaires ou des articles d'aide.
$COMPOSE exec -T php bin/console app:regulatory:sync --no-interaction || echo "AVERTISSEMENT : app:regulatory:sync a échoué ; les millésimes réglementaires ne sont pas à jour (voir ses messages ci-dessus)."

# Le site répond-il vraiment, vu de la machine ? (nginx et le DNS se vérifient de l'extérieur.)
if curl -fsS -o /dev/null "http://127.0.0.1:${HTTP_PORT}/health"; then
    echo "Peyra ${PEYRA_VERSION} est en ligne."
else
    echo "ERREUR : /health ne répond pas sur le port ${HTTP_PORT}." >&2
    $COMPOSE ps
    exit 1
fi

docker image prune -f >/dev/null
```

### Le bloc `server` de nginx

Sur la machine nginx, à côté de celui de Formatool. `IP_MACHINE_PEYRA` est l'adresse privée de la
machine de Peyra ; les chemins des certificats sont à adapter.

```nginx
# Peyra derrière le nginx de l'établissement, celui qui sert déjà Formatool, sur une AUTRE machine.
# À ajouter à sa configuration. À adapter : chemins des certificats, et IP_MACHINE_PEYRA (l'adresse
# privée de la machine de Peyra, jamais écrite dans le dépôt).
# Sur la machine de Peyra : le port 8080 n'est ouvert qu'à l'adresse de ce nginx (pare-feu).

server {
    listen 80;
    listen [::]:80;
    server_name pgi.beaupeyrat.org;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name pgi.beaupeyrat.org;

    ssl_certificate     /etc/letsencrypt/live/pgi.beaupeyrat.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pgi.beaupeyrat.org/privkey.pem;

    # Doit couvrir la taille maximale d'envoi de Peyra (pièces, FEC, archives). Trop bas, nginx
    # répond 413 avant que l'application ne puisse dire quoi que ce soit.
    client_max_body_size 64m;

    proxy_http_version 1.1;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host  $host;
    proxy_set_header X-Forwarded-Port  $server_port;

    # Mercure (suivi en direct) : un flux tenu ouvert. Pas de tampon, délais longs.
    location /.well-known/mercure {
        proxy_pass http://IP_MACHINE_PEYRA:8080;
        proxy_set_header Connection "";
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 24h;
        proxy_send_timeout 24h;
        chunked_transfer_encoding on;
    }

    location / {
        proxy_pass http://IP_MACHINE_PEYRA:8080;
        proxy_set_header Connection "";
        # Les PDF lourds (liasse, archive du dossier) et les distributions peuvent prendre du temps.
        proxy_read_timeout 120s;
    }
}
```

Ce qui compte dans ce bloc :

- **Les en-têtes `X-Forwarded-*`** disent à Peyra qui est le client et qu'il est venu en HTTPS. Peyra
  ne les croit que de `TRUSTED_PROXIES` ; sans eux, le journal des connexions et les limiteurs de
  tentatives ne voient que nginx, et le cookie de session perd sa sûreté (la production le force de
  toute façon `secure`, `SameSite=Lax`).
- **Mercure** (le suivi de la classe en direct) tient une connexion ouverte : sans tampon
  (`proxy_buffering off`), en HTTP/1.1 avec `Connection ""`, délais longs.
- **La taille des envois** a trois plafonds, du plus extérieur au plus intérieur : nginx
  (`client_max_body_size 64m`), PHP (`upload_max_filesize = 64M`, `post_max_size = 65M`, dans
  `frankenphp/conf.d/10-app.ini`), puis la politique d'envoi de Peyra, qui refuse avec un message.
  PHP est réglé à la hauteur de nginx : tout ce que nginx laisse passer atteint Peyra, qui sait dire
  pourquoi il refuse. En dessous, PHP jetterait le corps sans rien dire (FrankenPHP répond alors 200
  avec une page HTML, comme Formatool l'a constaté). On change les trois ensemble ou aucun.
- **HSTS** est envoyé par Peyra lui-même (`compose.prod.yaml`) ; nginx n'a pas à l'ajouter.

## Le compartiment S3 (Terraform)

Les fichiers des dossiers vont dans un compartiment S3 créé par la configuration Terraform du dépôt,
`infra/terraform/`. **Vous la lancez depuis votre propre machine**, avec votre profil AWS : ni la CI
ni la machine de Peyra n'ont de droits sur AWS, et aucune clé n'est écrite dans un fichier du dépôt.
Terraform lit la configuration, compare avec ce qui existe chez AWS, montre ce qu'il va faire
(`plan`), puis le fait quand vous le lui demandez (`apply`). Ce qu'il a créé, il le note dans un
fichier d'état, `infra/terraform/terraform.tfstate`, sur votre machine.

Ce qui est créé (dix ressources, dans la région choisie) :

| Ressource | Réglage |
|---|---|
| le compartiment | nom choisi (`bucket_name`) ; Terraform refuse de le détruire (`prevent_destroy`) |
| propriété des objets | « Bucket owner enforced » : les ACL sont désactivées, seuls les droits IAM comptent |
| blocage de l'accès public | les quatre cases cochées ; aucun fichier n'a d'adresse publique, pas de CloudFront |
| chiffrement | SSE-S3 (AES-256, clés gérées par S3, sans KMS) |
| versionnement | activé : un fichier supprimé ou remplacé reste récupérable |
| cycle de vie | les versions anciennes effacées après 90 jours (`noncurrent_version_days`), les envois inachevés après 7 ; **jamais un fichier courant** |
| politique du compartiment | HTTPS obligatoire, pour tous |
| utilisateur IAM `peyra-storage` | celui avec lequel Peyra se connecte, et sa clé d'accès |
| sa politique | `s3:ListBucket` sur le compartiment, `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject` sur ses objets, rien d'autre |

La politique de l'utilisateur est exactement ce que Peyra fait sur le compartiment : lire, écrire,
vérifier qu'un fichier existe et sa taille, lister les fichiers d'un dossier, copier un dossier,
supprimer. `tests/Service/Shared/Storage/S3BucketPolicyTest.php` la compare aux requêtes que le code
envoie réellement ; la CI vérifie que la configuration est bien formée (`terraform fmt`,
`terraform validate`). Ne sont pas accordés : effacer une version (cela viderait le filet du
versionnement), lire ou poser une ACL (Peyra n'en envoie aucune), régler le compartiment.

### Ce qu'il vous faut

- **Terraform 1.5 ou plus récent** sur votre machine (`terraform version`). Sur macOS :
  `brew tap hashicorp/tap && brew install hashicorp/tap/terraform` (le `terraform` du dépôt
  principal de Homebrew est arrêté à 1.5.7 : il convient aussi). Ailleurs :
  <https://developer.hashicorp.com/terraform/install>.
- **Un profil AWS** sur cette machine, dans `~/.aws/config` et `~/.aws/credentials`, qui a le droit
  de créer un compartiment S3 et de le régler, un utilisateur IAM, sa clé et sa politique (un profil
  d'administrateur du compte convient). Pour en créer un avec l'AWS CLI :
  `aws configure --profile <nom>`. Vérification, facultative : `aws sts get-caller-identity
  --profile <nom>` dit sur quel compte vous allez agir.
- Un clone du dépôt sur votre machine (pas besoin de la pile Docker de Peyra).
- Choisir le **nom du compartiment** : unique dans tout AWS, 3 à 63 caractères, minuscules,
  chiffres et tirets (par exemple `<établissement>-peyra-documents`). Il ne pourra plus changer.

### Renseigner les variables

```console
cd infra/terraform
cp terraform.tfvars.example terraform.tfvars
```

Ouvrez `terraform.tfvars` (ignoré par git) et remplissez :

| Variable | Valeur |
|---|---|
| `aws_profile` | le nom de votre profil AWS |
| `aws_region` | `eu-west-3` (Paris), sauf raison contraire ; c'est `S3_REGION` |
| `bucket_name` | le nom choisi ; l'exemple `exemple-peyra-documents` est refusé |
| `prefix` | à laisser vide : le compartiment est à Peyra seul (sinon un dossier terminé par `/`, auquel l'utilisateur est borné) |
| `iam_user_name`, `noncurrent_version_days`, `incomplete_upload_days` | facultatives, valeurs par défaut `peyra-storage`, 90, 7 |

### Créer le compartiment

Toujours depuis `infra/terraform` :

```console
terraform init
terraform plan -out=peyra.tfplan
```

`init` télécharge le fournisseur AWS de Terraform (la version notée dans `.terraform.lock.hcl`) dans
`.terraform/` ; il ne contacte pas AWS. `plan` lit votre compte AWS et écrit ce qu'il ferait dans
`peyra.tfplan`, sans rien changer. **Relisez-le** : la première fois, il doit finir par
`Plan: 10 to add, 0 to change, 0 to destroy.`, et ne lister que des créations (`+`) : le
compartiment, ses cinq réglages et sa politique, l'utilisateur, sa clé et sa politique. Une erreur à ce stade vient du profil (identifiants absents ou expirés, droits
manquants) ou du nom (déjà pris dans AWS : `BucketAlreadyExists` n'apparaîtra qu'à l'`apply`).
Puis :

```console
terraform apply peyra.tfplan
```

`apply` exécute exactement le plan relu, sans nouvelle question, et finit par
`Apply complete! Resources: 10 added, 0 changed, 0 destroyed.` suivi des sorties. Si le nom était
déjà pris (`BucketAlreadyExists`), Terraform a pu créer une partie du reste, l'utilisateur IAM par
exemple, et l'a noté dans son état : changez `bucket_name`, puis recommencez `plan` et `apply`, qui
ne créent que ce qui manque.

### Recopier les sorties dans `.env.prod.local`

Les sorties portent le nom des variables de Peyra. `terraform output` les affiche, la clé secrète
masquée (`<sensitive>`) ; le bloc complet, clé secrète comprise, prêt à coller :

```console
terraform output -raw env_prod_local
```

```
PEYRA_STORAGE=s3
S3_BUCKET=<le nom choisi>
S3_PREFIX=
S3_REGION=eu-west-3
S3_ACCESS_KEY_ID=AKIA…
S3_SECRET_ACCESS_KEY=…
S3_ENDPOINT=
```

Sur la machine de Peyra, dans `.env.prod.local`, **remplacez** les lignes du bloc
`###> peyra/storage ###` par celles-ci (ne les ajoutez pas à la suite : une variable écrite deux fois
prête à confusion), puis relancez `./deploy-prod.sh`, qui recrée `php` et `worker` avec ces valeurs.
Faites passer la clé secrète par un canal sûr (une session SSH, pas un courriel ni une messagerie).
Une valeur seule se lit aussi : `terraform output -raw S3_SECRET_ACCESS_KEY`.

### Le fichier d'état

`infra/terraform/terraform.tfstate` est ce qui permet à Terraform de modifier plus tard ce qu'il a
créé. **Il contient la clé secrète en clair.** Gardez-le (il est ignoré par git ; ne le supprimez
pas), et sauvegardez-le chiffré avec les autres fichiers secrets du serveur. Le perdre ne touche pas
au compartiment, mais Terraform ne le connaîtrait plus (il faudrait le réimporter, `terraform
import`). `peyra.tfplan` peut être supprimé après l'`apply`.

### Plus tard

- **Vérifier que rien n'a dérivé** : `terraform plan` doit répondre `No changes.`
- **Changer un réglage** (durée de conservation des versions, par exemple) : modifiez
  `terraform.tfvars`, puis `terraform plan -out=peyra.tfplan`, relecture, `terraform apply
  peyra.tfplan`. Un plan qui annonce `destroy` ou `must be replaced` sur le compartiment est refusé
  par Terraform lui-même (`prevent_destroy`) : c'est voulu, ce serait la perte de tous les fichiers.
- **Changer la clé d'accès** (une clé exposée, ou par hygiène) : `terraform plan
  -replace=aws_iam_access_key.storage -out=peyra.tfplan`, relecture, `terraform apply peyra.tfplan`,
  puis recopier les deux nouvelles valeurs `S3_ACCESS_KEY_ID` et `S3_SECRET_ACCESS_KEY` et relancer
  `./deploy-prod.sh`. L'ancienne clé cesse de fonctionner dès l'`apply`.
- **Récupérer un fichier supprimé** (dans les 90 jours) : console AWS, le compartiment, « Afficher
  les versions », supprimer le marqueur de suppression du fichier.
- Terraform refuse de détruire le compartiment, même par `terraform destroy`. Le supprimer vraiment
  est un geste à faire exprès : retirer le bloc `prevent_destroy` de `bucket.tf`, vider le
  compartiment (versions comprises), puis `terraform destroy`.

## Premier déploiement

1. Sur la machine nginx : le bloc `server`, le certificat, `nginx -t && systemctl reload nginx`.
2. Sur la machine de Peyra : cloner le dépôt, ouvrir le port 8080 à nginx seul.
3. Créer le compartiment S3 depuis votre machine (« Le compartiment S3 (Terraform) », ci-dessus).
   Créer `.env.prod.local` (ci-dessus, avec les sorties de Terraform), déposer `certs/ldap-ca.pem`,
   écrire `deploy-prod.sh`.
4. Lancer `./deploy-prod.sh`. Il construit l'image (plusieurs minutes la première fois), démarre
   `php`, qui attend la base puis joue les migrations, puis `worker` et `gotenberg`, et finit par
   « Peyra <commit> est en ligne. »
5. Vérifier l'annuaire avec un compte réel (les variables à exporter d'abord : « Diagnostics ») :

   ```console
   dc exec php bin/console app:ldap:check <identifiant>
   ```

   La commande dit si l'annuaire répond, si le compte de service se lie, et comment Peyra lit la
   personne : son DN, l'attribut d'où vient son identifiant stable (`objectGUID` attendu), son nom,
   ses groupes, et ce que la règle d'entrée en ferait. Elle n'affiche aucun secret.
6. Se connecter sur `https://pgi.beaupeyrat.org` avec un identifiant de `PEYRA_ADMIN_LOGINS`, puis,
   dans Paramètres › Administration : l'année scolaire, les classes, la correspondance des groupes
   de l'annuaire avec les classes. Les étudiants sont placés à leur première connexion.
7. Ouvrir Paramètres › Administration › Journal des connexions : la colonne « Adresse » doit montrer
   l'adresse du poste, pas celle de nginx (voir « Diagnostics »).

## Mise à jour

```console
./deploy-prod.sh
```

Le script jette toute modification locale du clone, se place sur `origin/main`, reconstruit sans
cache, redémarre (les sessions survivent : elles sont sur un volume), joue les migrations nouvelles,
installe les millésimes réglementaires du dépôt (`app:regulatory:sync`, ci-dessous) et vérifie
`/health`. Avant une mise à jour qui porte des migrations, faites une sauvegarde de la
base (ci-dessous) : on ne revient pas en arrière sur une migration jouée.

### Les millésimes réglementaires

Les taux, plafonds, seuils et barèmes que les calculs lisent à la date du dossier sont versionnés
dans `config/regulatory/<année>.yaml`, avec leur source officielle et leur marque « vérifiée ».
`app:regulatory:sync` (lancé par `deploy-prod.sh` après chaque démarrage) les installe dans la base
**en n'ajoutant que des lignes** : une valeur absente est ajoutée, une valeur que le fichier a changée
est ajoutée de nouveau et remplace l'ancienne pour tout calcul (l'ancienne reste dans l'historique),
une valeur qu'un administrateur a saisie depuis sous Paramètres › Paramètres réglementaires n'est
jamais écrasée (la commande la signale « conservée »). Un fichier fautif n'installe rien et la
commande dit pourquoi, ligne par ligne. Elle peut être relancée sans risque ; `--dry-run` dit ce
qu'elle ferait sans rien écrire :

```console
dc exec php bin/console app:regulatory:sync --dry-run
```

## Retour en arrière

Le code revient en arrière par git, la base par sa sauvegarde :

1. Si la version fautive a joué des migrations, restaurer la sauvegarde prise juste avant (voir
   « Sauvegarde ») ; les migrations de Peyra ne sont pas rejouées à l'envers automatiquement.
2. Revenir sur le commit précédent sans attendre un correctif de `main` :

   ```console
   git checkout <commit-précédent>
   export PUBLIC_URL=https://pgi.beaupeyrat.org HTTP_PORT=8080 PEYRA_VERSION=$(git rev-parse --short HEAD)
   docker compose -f compose.yaml -f compose.prod.yaml build --pull
   docker compose -f compose.yaml -f compose.prod.yaml up --wait --remove-orphans
   ```

3. Corriger sur `main` (par une pull request, comme toute livraison). Le prochain
   `./deploy-prod.sh` remet le clone sur `origin/main`.

## Sauvegarde

- **La base**, avec l'utilisateur de Peyra, qui a tous les droits nécessaires sur sa base :

  ```console
  mariadb-dump --single-transaction --routines --triggers -h <serveur> -u user_pgi -p pgi_mngmt | gzip > pgi_mngmt-$(date +%F).sql.gz
  ```

  Restauration : `gunzip -c pgi_mngmt-AAAA-MM-JJ.sql.gz | mariadb -h <serveur> -u user_pgi -p pgi_mngmt`.
- **Les fichiers des dossiers** : sur S3 (`PEYRA_STORAGE=s3`), le compartiment créé par Terraform
  est versionné : un fichier supprimé ou remplacé reste récupérable 90 jours (« Le compartiment S3
  (Terraform) »). En `local`, ils sont dans le volume `app_storage`, à copier avec la base :

  ```console
  docker run --rm -v peyra_app_storage:/d:ro -v "$PWD":/out debian:13-slim tar czf /out/peyra-fichiers-$(date +%F).tgz -C /d .
  ```

  (le nom du volume est préfixé par celui du projet Compose : `docker volume ls`). Les autres
  volumes ne tiennent que les sessions (`php_sessions`), les verrous (`app_locks`) et l'état de
  Caddy : rien à sauvegarder.
- **Les fichiers du serveur** (`.env.prod.local`, `deploy-prod.sh`, `certs/`), et l'état Terraform
  (`infra/terraform/terraform.tfstate`, sur la machine d'où vous l'avez lancé) : une copie hors de la
  machine, chiffrée ; sans `APP_SECRET`, toute session ouverte est perdue, sans les clés Mercure le
  suivi en direct s'arrête.

## Diagnostics

Compose substitue `PUBLIC_URL` dans `compose.prod.yaml` pour **toute** commande, même `logs` ou
`exec`, et refuse sans elle. À la main, commencez donc par :

```console
cd /srv/peyra
export PUBLIC_URL=https://pgi.beaupeyrat.org HTTP_PORT=8080 PEYRA_VERSION=$(git rev-parse --short HEAD)
alias dc='docker compose -f compose.yaml -f compose.prod.yaml'
```

| Question | Où regarder |
|---|---|
| Le conteneur répond-il ? | `curl -fsS http://127.0.0.1:8080/health` sur la machine de Peyra (200, sans base) ; de l'extérieur, `https://pgi.beaupeyrat.org/health` |
| Que fait la pile ? | `dc ps`, puis `dc logs --tail=200 php` (ou `worker`, `gotenberg`) ; les journaux sont bornés à 5 × 10 Mo par service |
| Les migrations sont-elles passées ? | les journaux de `php` au démarrage (« Successfully migrated ») ; `dc exec php bin/console doctrine:migrations:status` |
| Personne ne peut se connecter | `dc exec php bin/console app:ldap:check` (annuaire, compte de service, base de recherche, certificat), puis avec un identifiant |
| Une personne est refusée | `dc exec php bin/console app:ldap:check <identifiant>` : ses groupes et la règle d'entrée ; Paramètres › Administration › Journal des connexions |
| Le journal des connexions montre l'adresse de nginx, ou une adresse en `172.…` | `TRUSTED_PROXIES` ne contient pas l'adresse que Peyra voit. Docker la remplace par celle de son pont quand nginx arrive en IPv6 (proxy de Docker) : mettez l'adresse affichée, ou `REMOTE_ADDR` si le pare-feu ferme le port à tout autre |
| Les liens ou les redirections partent en `http://` | `TRUSTED_PROXIES` (ci-dessus) et l'en-tête `X-Forwarded-Proto` du bloc nginx |
| Le suivi en direct ne bouge pas | la `location /.well-known/mercure` de nginx (`proxy_buffering off`), les clés Mercure, `PUBLIC_URL` |
| Un document PDF ne sort pas (« Le document n'a pas pu être produit ») | `dc logs --tail=100 gotenberg` et `dc logs php` (ligne « Gotenberg failed on … ») ; `dc restart gotenberg` |
| Un envoi de fichier échoue | `PEYRA_STORAGE` et les `S3_*` (les sorties de Terraform) ; les journaux de `php` : `AccessDenied` = clé ou politique (`terraform plan` doit dire `No changes.`), `NoSuchBucket` = nom ou région ; au-delà de 64 Mo, c'est nginx qui refuse (413) |
| Une tâche planifiée échoue | `dc logs worker` : une ligne par exécution (« Scheduled task … exited with code … »), la fin de sa sortie quand elle échoue |
| Une valeur réglementaire manque ou paraît fausse | `dc exec php bin/console app:regulatory:sync --dry-run` (ce qui reste à installer, les valeurs « conservées » d'un administrateur) ; Paramètres › Paramètres réglementaires, la fiche de la valeur et son historique |
| « À propos » affiche une autre version | `PEYRA_VERSION` est exportée par `deploy-prod.sh` : un déploiement à la main doit l'exporter aussi |

## Essai local de la pile de production

La pile de production se monte sur une machine de développement, à côté de celle de dev, sous un
autre nom de projet et un autre port, contre une base MariaDB 11.8 jetable et l'annuaire fictif
(un fichier Compose de plus, hors du dépôt, qui ajoute `database` et `openldap`), avec un
`.env.prod.local` fictif à retirer après :

```console
export PUBLIC_URL=https://pgi.example.org HTTP_PORT=8280 PEYRA_VERSION=essai
docker compose -p peyra-prodtest -f compose.yaml -f compose.prod.yaml -f /tmp/…/essai.yaml up --wait
curl -fsS http://127.0.0.1:8280/health
docker compose -p peyra-prodtest down -v      # cette pile-là seulement
```
