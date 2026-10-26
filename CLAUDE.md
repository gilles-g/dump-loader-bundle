# dump-loader

Bundle Symfony : chargement d'un dump SQL dans la base de l'application, avec UI web de suivi temps réel.

## Outillage : passer par le Makefile

Toutes les commandes d'outillage passent par `make`. Ne jamais retaper `vendor/bin/phpstan`,
`vendor/bin/php-cs-fixer`, `vendor/bin/phpunit`, `vendor/bin/rector` ni `composer test` à la main :
si la commande manque, on ajoute une cible au Makefile.

`make help` liste les cibles. Les principales :

| Cible | Effet |
|---|---|
| `make quality` | `phpcsfix` + `phpstan` + `test` — à passer avant de rendre la main |
| `make ci` | même chose en lecture seule (style, phpstan, rector, tests) |
| `make phpcsfix` | corrige le style **et pose les en-têtes de licence** |
| `make phpstan` | analyse statique, level max |
| `make test` | toute la suite (purge d'abord le cache du kernel de test) |
| `make test-mysql-docker` | le groupe `mysql` (restaure un vrai dump dans la MariaDB du compose) |
| `make test-filter FILTER=it_should_x` | un test précis |
| `make test-file FILE=tests/...Test.php` | un fichier |
| `make coverage` | couverture texte (exigence : 100 %) |
| `make rector-check` | ce que Rector changerait |

## Pièges du projet

- `make test` purge `/tmp/DumpLoaderBundle` : sans ça le container compilé du kernel de test masque
  les changements de `src/Resources/config/services.php`.
- PHPStan ne voit pas le câblage DI. Un bundle « vert » en statique peut ne pas booter :
  `ContainerLintTest` est le seul filet.
- `services.php` n'utilise ni `autowire()` ni `load()` : tout alias d'interface doit être déclaré
  explicitement, sinon `getContainer()->get(X::class)` échoue en test.
- `phpunit.xml.dist` exclut le groupe `mysql` du run par défaut : un test taggé `#[Group('mysql')]`
  ne tourne ni avec `make test`, ni en couverture, seulement avec `make test-mysql*`.
- `composer install` exige un `COMPOSER_HOME` propre (le token GitHub global de la machine est
  invalide) — `make install` s'en charge.

## Conventions

- Code 100 % anglais (c'est un bundle open source), textes d'UI traduits `fr` + `en`.
- En-tête de licence : jamais écrit à la main, `make phpcsfix` le pose.
- Tests : `#[Test]` + méthodes `it_should_*` (PHPUnit 11).
