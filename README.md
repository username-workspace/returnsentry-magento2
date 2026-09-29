# ReturnSentry pour Magento 2

[English version below](#returnsentry-for-magento-2)

Le module `ReturnSentry_Connector` (paquet Composer `returnsentry/module-connector`, licence MIT) relie une boutique Magento Open Source 2.4.7 ou plus, Mage-OS ou Adobe Commerce à une instance ReturnSentry. Il envoie les clients, commandes, colis, retours et avoirs, demande une décision au paiement, à chaque retour et avant un avoir, et charge le beacon sur la boutique. Ses points de décision sont branchés sur les services de Magento : ils valent pour Luma, Hyvä, GraphQL et REST.

1. [Ce que fait le module](#1-ce-que-fait-le-module)
2. [Prérequis et installation](#2-prérequis-et-installation)
3. [Configuration](#3-configuration)
4. [Boutique découplée (GraphQL, REST)](#4-boutique-découplée-graphql-rest)
5. [Obligations légales du marchand](#5-obligations-légales-du-marchand)
6. [Exploitation](#6-exploitation)
7. [Dépannage](#7-dépannage)
8. [Développement](#8-développement)

## 1. Ce que fait le module

| Point de décision | Luma, Hyvä | GraphQL | REST | Administration | Comportement |
|---|---|---|---|---|---|
| Synchronisation | oui | oui | oui | oui | Chaque client, commande, numéro de suivi, avoir et retour enregistré part vers ReturnSentry par la file de messages. |
| Évaluation au paiement | oui | `placeOrder` | `POST /V1/carts/mine/payment-information`, `PUT /V1/carts/mine/order`, variantes `guest-carts` | non | Décision `allow`, `review`, `restrict_payment` ou `block`, appliquée en Application, gardée sans effet en Observation. |
| Filtrage des moyens de paiement | oui | `available_payment_methods` | `GET /V1/carts/mine/payment-methods`, `POST /V1/carts/mine/shipping-information` | non | En Application, masque les moyens à risque configurés sur `restrict_payment`. |
| Conditions de retour avant la commande | Luma : avis dans l'étape de paiement. Hyvä Checkout : non | champ `returnsentry_return_terms` du panier | `GET .../returnsentry-return-terms` | panneau de la commande | En Application : les conditions payantes sont montrées, puis déclarées affichées au passage de la commande. |
| Rappel dans la confirmation | email de commande de Magento | idem | idem | non | Bloc d'email à ajouter au modèle de confirmation. |
| Évaluation des retours | RMA Adobe Commerce, `ReturnReporterInterface` | `requestReturn`, `requestGuestReturn` (RMA) | `POST /V1/returns` (RMA) | RMA | Décision affichée à l'équipe, jamais appliquée par le module. |
| Autorisation de remboursement | non | non | `POST /V1/order/:id/refund`, `POST /V1/invoice/:id/refund`, `POST /V1/creditmemo/refund` | avoir | En Application, un avoir peut être arrêté ; en Observation, jamais. |
| Dérogation | non | non | non | formulaire d'avoir | Un administrateur habilité rembourse malgré un arrêt, avec un motif. |
| Beacon | chargé par le module | chargé par le storefront | chargé par le storefront | non | Script de ReturnSentry sur la boutique, avec le consentement du visiteur. |
| Identifiant visiteur | cookies `rs_vid` et `rs_consent` | en-têtes `X-ReturnSentry-*` | en-têtes `X-ReturnSentry-*` | non | Joint à l'évaluation et à la commande seulement avec le consentement. |
| Contrôles de l'entrepôt | code PHP, `InspectionReporterInterface` | non | non | non | Constats par article rattachés au retour, envoyés avec des essais ; ils font redécider l'autorisation de remboursement. |
| Effacement | non | non | non | suppression du client | Efface le client chez ReturnSentry, avec des essais jusqu'à confirmation, à sa suppression dans Magento, ou par `CustomerErasureInterface` pour un flux RGPD qui anonymise. |

Le module ne fait pas ceci :

- Il ne bloque jamais la création d'un retour ni la rétractation.
- Il n'évalue ni les commandes créées dans l'administration, ni celles passées avec un jeton d'administrateur ou d'intégration, ni celles importées par `POST /V1/orders` : elles sont synchronisées, sans décision ni filtrage des moyens de paiement.
- Il n'affiche aucune condition de retour hors Application, ni sur Hyvä Checkout ou un tunnel en une page, qui les affichent eux-mêmes (section 5).
- Il n'ajoute pas les conditions à l'email de confirmation tant que le marchand n'a pas ajouté la ligne du bloc à son modèle.
- Il ne reçoit aucun webhook de ReturnSentry : un avoir arrêté repart quand l'administrateur le soumet de nouveau.
- Il n'envoie pas au client le lien de preuve d'une décision de remboursement : l'administrateur le voit dans le message d'arrêt.
- Il ne rattrape pas seul les événements survenus pendant que la boutique était déconnectée : la commande `returnsentry:backfill` le fait (section 6.3).
- `ReturnReporterInterface`, `InspectionReporterInterface` et `CustomerErasureInterface` sont des contrats PHP, sans route REST ni GraphQL.

## 2. Prérequis et installation

### 2.1 Prérequis

- Magento Open Source 2.4.7 ou plus, Mage-OS ou Adobe Commerce ; PHP 8.2 à 8.5.
- Le cron de Magento, qui lance les tâches `returnsentry_sync_retry` et `returnsentry_apply_retention` et, selon `env.php`, le consommateur de file.
- Une instance ReturnSentry et, par site web, deux clés créées dans la console ReturnSentry (Intégrations › Clés API) par un administrateur du marchand : une clé secrète `rs_sk_…` pour Magento et une clé publique `rs_pk_…` pour le beacon. La console n'affiche une clé qu'à sa création.
- Un site web dont la devise de base diffère des autres est un marchand ReturnSentry à part, avec ses propres clés : les montants partent dans la devise de base.
- Sur Magento Open Source et Mage-OS, une extension de retours, ou du code du marchand, qui déclare les retours par `ReturnReporterInterface` (section 6.5).

### 2.2 Installation

Trois étapes : installer le paquet, mettre à jour Magento, brancher ReturnSentry (section 2.3).

```bash
composer config repositories.returnsentry vcs https://github.com/username-workspace/returnsentry-magento2
composer require returnsentry/module-connector:^1.0
bin/magento setup:upgrade
export RETURNSENTRY_URL=https://returnsentry.example.com RETURNSENTRY_PUBLISHABLE_KEY=rs_pk_...
read -rs RETURNSENTRY_SECRET_KEY && export RETURNSENTRY_SECRET_KEY
bin/magento returnsentry:connect
unset RETURNSENTRY_SECRET_KEY RETURNSENTRY_SALT
```

Le paquet vient du dépôt public `username-workspace/returnsentry-magento2`, qui ne contient que ce module, et de ses étiquettes `X.Y.Z`, une par version (section 8) : aucune clé n'est nécessaire. `setup:upgrade` active le nouveau module.

Sans accès à GitHub depuis le serveur, Composer prend une copie du module, un clone de ce dépôt par exemple, déclarée comme dépôt `path`. Garder cette copie au même chemin tant que le module est installé : `composer install`, `composer update` et `module:uninstall` la relisent.

```bash
composer config repositories.returnsentry '{"type": "path", "url": "/chemin/vers/returnsentry-magento2", "options": {"symlink": false}}'
composer require returnsentry/module-connector:@dev
bin/magento setup:upgrade
```

En mode production, compiler et déployer les fichiers statiques après `setup:upgrade`, avant `returnsentry:connect` :

```bash
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```

Le module crée les tables `returnsentry_return`, `returnsentry_inspection`, `returnsentry_refund_authorization` et `returnsentry_sync_retry`, les colonnes `returnsentry_visitor_id`, `returnsentry_action`, `returnsentry_observed_action`, `returnsentry_level`, `returnsentry_score`, `returnsentry_return_paid`, `returnsentry_return_label_cost` et `returnsentry_erased` sur `sales_order`, et la colonne `returnsentry_action` sur `sales_order_grid`.

### 2.3 Brancher ReturnSentry

Une commande enregistre l'adresse et les clés, teste la connexion et n'active le module que si le test réussit :

```bash
export RETURNSENTRY_URL=https://returnsentry.example.com RETURNSENTRY_PUBLISHABLE_KEY=rs_pk_...
read -rs RETURNSENTRY_SECRET_KEY && export RETURNSENTRY_SECRET_KEY
bin/magento returnsentry:connect
unset RETURNSENTRY_SECRET_KEY RETURNSENTRY_SALT
```

`RETURNSENTRY_URL`, `RETURNSENTRY_SECRET_KEY`, `RETURNSENTRY_PUBLISHABLE_KEY` et `RETURNSENTRY_SALT` tiennent lieu de `--url`, `--secret-key`, `--publishable-key` et `--salt`. Lues dans l'environnement, les clés restent hors de l'historique du shell : `read -rs` lit la clé secrète sans l'afficher, et `unset` retire les secrets de la session une fois la commande passée. Les options restent possibles, au prix de l'historique :

```bash
bin/magento returnsentry:connect --url https://returnsentry.example.com --secret-key rs_sk_... --publishable-key rs_pk_...
```

La commande :

1. vérifie les formats comme l'administration : adresse en `https://`, clé secrète `rs_sk_…`, clé publique `rs_pk_…`, secret de pseudonymisation de 32 caractères au moins ; elle refuse aussi un `--website` vide ou `0` et, sans `--force-salt`, un secret de pseudonymisation différent de celui déjà enregistré, qui séparerait les clients pseudonymisés de leur historique (section 3.4) ;
2. enregistre les réglages à la portée par défaut, ou à celle du site web de `--website <code>`, par le modèle d'enregistrement de l'administration, qui chiffre la clé secrète et le secret de pseudonymisation et nettoie le cache de configuration ; à une première connexion, le module reste éteint le temps du test, et une portée déjà connectée reste allumée. `--salt` active aussi la pseudonymisation (section 3.4). Un chemin verrouillé dans `app/etc/env.php` ou `config.php` garde sa valeur, comme dans l'administration, et la commande le nomme ;
3. applique le profil de `--profile <fichier.json>` (section 3.6), vérifié en entier avant le premier enregistrement ;
4. teste la connexion comme le bouton « Tester la connexion » ;
5. si le test réussit, active le module à la même portée ; sinon, affiche la raison et sort avec le code 1, le module restant comme l'étape 2 l'a laissé. Un format, une adresse ou un profil refusé sort avec le code 2, sans activer le module ;
6. affiche le mode des décisions au paiement et de l'autorisation de remboursement, qu'elle ne change pas (`observe` par défaut, section 3.5), et les étapes suivantes.

Relancer la commande, pour changer de clé par exemple, remplace les réglages de connexion et refait le test sans éteindre le module : les envois continuent et les arrêts de remboursement enregistrés restent appliqués. Si ce test échoue, pour une panne comme pour une clé erronée, le module reste allumé avec les nouveaux réglages et échoue ouvert jusqu'à leur correction : une clé ou une adresse refusée est signalée (section 6.6) et les envois refusés pour la clé sont réessayés (section 6.2). Les mêmes réglages sont dans l'administration (**Magasins > Configuration > ReturnSentry > ReturnSentry**, groupe Connexion, bouton « Tester la connexion » pour la portée affichée) et chaque chemin de la section 3.1 reste accessible à `bin/magento config:set`.

Vérifier ensuite que le consommateur tourne :

```bash
bin/magento queue:consumers:list | grep returnsentry.sync
bin/magento queue:consumers:start returnsentry.sync
```

Le cron de Magento démarre le consommateur de lui-même quand `cron_consumers_runner` est actif dans `env.php` ; sinon, lancer la seconde commande sous un superviseur de processus.

### 2.4 Désinstallation

```bash
bin/magento module:uninstall --remove-data ReturnSentry_Connector
bin/magento setup:upgrade
bin/magento cache:flush
```

Avant de désinstaller, attendre que le message système des effacements en attente disparaisse : la table `returnsentry_sync_retry` part avec le module, et les effacements qu'elle garde avec elle. Les réglages restent dans `core_config_data` (`path LIKE 'returnsentry/%'`). Les données déjà envoyées restent chez ReturnSentry, où l'effacement se fait par marchand ou par client.

## 3. Configuration

### 3.1 Référence

Une vue magasin est **connectée** quand `returnsentry/general/enabled` vaut `1`, que l'adresse et la clé secrète sont renseignées et, si la pseudonymisation est active, que son secret compte au moins 32 caractères. Sans connexion, la vue magasin n'envoie rien, n'évalue rien, ne garde pas d'identifiant visiteur sur ses commandes, ne charge pas le beacon et n'affiche pas les panneaux, sauf les effacements, les dérogations et les contrôles déclarés par `InspectionReporterInterface`, qui partent dès que l'adresse et la clé secrète sont là.

| Chemin | Portée | Défaut | Effet |
|---|---|---|---|
| `returnsentry/general/enabled` | vue magasin | `0` | Interrupteur général. |
| `returnsentry/general/api_url` | site web | vide | Adresse de ReturnSentry, en `https://` ; `http://` n'est accepté que pour `localhost`, `127.0.0.1` et `[::1]`. Seule l'origine est gardée. La console est à la même adresse. |
| `returnsentry/general/secret_key` | site web | vide | Clé `rs_sk_…`, chiffrée en base, marquée sensible pour Magento, jamais journalisée. |
| `returnsentry/general/publishable_key` | site web | vide | Clé `rs_pk_…` du beacon ; une clé `rs_sk_` y est refusée. |
| `returnsentry/general/checkout_timeout_ms` | site web | `800` | Budget de l'évaluation au paiement, et des panneaux de l'administration. Minimum 50. Au-delà, le paiement continue comme sur `allow`. |
| `returnsentry/general/timeout_ms` | site web | `3000` | Budget des autres appels : synchronisation, retours, autorisations de remboursement. Minimum 100. |
| `returnsentry/features/checkout_decisions` | vue magasin | `observe` | `off`, `observe` ou `enforce` : évaluation au paiement, filtrage des moyens de paiement et conditions de retour. |
| `returnsentry/features/return_assessment` | vue magasin | `1` | Évalue chaque retour. Les retours sont synchronisés même à `0`. |
| `returnsentry/features/refund_authorization` | vue magasin | `observe` | `off`, `observe` ou `enforce` : autorisation de remboursement. |
| `returnsentry/features/beacon` | vue magasin | `1` | Charge le beacon sur le front Magento. |
| `returnsentry/features/excluded_payment_methods` | vue magasin | `globale` | « Moyens de paiement exclus du contrôle » : codes des moyens dont un checkout externe gère le paiement et les remboursements (`globale` pour Global-e). Le passage d'une commande payée par l'un d'eux n'est ni évalué ni arrêté, et ses avoirs ne demandent aucune autorisation de remboursement ; la liste des moyens de paiement, calculée avant le choix d'un moyen, reste évaluée comme pour tout panier ; commandes, avoirs et retours sont toujours synchronisés, et les retours évalués (sections 6.4 et 6.6). |
| `returnsentry/checkout/risky_payment_methods` | vue magasin | vide | Codes des moyens à masquer sur `restrict_payment` (paiement différé, fractionné, à la livraison). Vide : aucun filtrage. |
| `returnsentry/privacy/pseudonymize` | site web | `0` | Envoie des empreintes à la place des données en clair (section 3.4). |
| `returnsentry/privacy/salt` | site web | vide | Secret de pseudonymisation, 32 caractères au moins, chiffré. |
| `returnsentry/mapping/return_reasons` | vue magasin | motifs par défaut d'Adobe Commerce | Motifs de retour de la boutique vers les motifs standard (section 3.2). |
| `returnsentry/mapping/refund_code_field` | vue magasin | vide | Nom du champ de l'avoir qui porte le code motif du remboursement. |
| `returnsentry/mapping/refund_codes` | vue magasin | vide | Codes motif d'avoir vers les catégories standard. |
| `returnsentry/mapping/refund_with_return` | vue magasin | `return` | Catégorie d'un avoir rattaché à un retour. |
| `returnsentry/mapping/refund_adjustment_only` | vue magasin | `commercial_gesture` | Catégorie d'un avoir sans article. |
| `returnsentry/mapping/refund_not_shipped` | vue magasin | `other` | Catégorie d'un avoir sur une commande non expédiée. |
| `returnsentry/mapping/refund_shipped` | vue magasin | `other` | Catégorie d'un avoir sur une commande expédiée, sans retour. |
| `returnsentry/beacon/loading` | vue magasin | `consent` | `consent` : après consentement, et sur les pages de connexion et de création de compte ; `always` : sur toutes les pages (Royaume-Uni). |
| `returnsentry/beacon/consent_cookie` | vue magasin | vide | Nom du cookie du gestionnaire de consentement. |
| `returnsentry/beacon/consent_cookie_value` | vue magasin | vide | Élément du cookie qui vaut consentement (section 3.3). |
| `returnsentry/beacon/consent_event` | vue magasin | `returnsentry:consent` | Événement de consentement sur `window`. |

### 3.2 Correspondances

**Motifs de retour.** Chaque libellé ou code de motif de la boutique, dans chaque langue, reçoit un motif standard : `size_fit`, `changed_mind`, `quality`, `damaged_defective`, `not_as_described`, `wrong_item`, `late_delivery`, `multiple_sizes`, `other` ou `unknown`. Un motif standard envoyé tel quel est accepté ; un libellé absent de la table vaut `unknown`. Pour Adobe Commerce, la table reçoit les libellés de l'administration (vue magasin 0) ; un motif « Autre » saisi librement vaut `other`. La table ne fait jamais d'un retour un retour à l'expéditeur, quel que soit son motif.

**Avoirs.** Chaque avoir reçoit une catégorie, dans cet ordre :

1. si `returnsentry/mapping/refund_code_field` nomme un champ et que l'avoir le porte, la table `returnsentry/mapping/refund_codes` décide ; un code absent de la table vaut `other` ;
2. sinon, si l'avoir est rattaché à un retour, `returnsentry/mapping/refund_with_return`, sauf pour un retour à l'expéditeur, qui vaut toujours `return_to_sender`. Un avoir est rattaché au plus ancien retour ouvert (ni refusé ni annulé) qui partage au moins un produit avec lui, un retour reçu passant en premier ; un avoir sans article se rattache à tout retour ouvert ;
3. sinon, `returnsentry/mapping/refund_adjustment_only` pour un avoir sans article, `returnsentry/mapping/refund_not_shipped` pour une commande non expédiée, `returnsentry/mapping/refund_shipped` pour une commande expédiée.

Sur une commande payée dans une autre devise que la devise de base (vente transfrontalière), `return_to_sender` devient `return`, pour l'avoir comme pour le retour.

Les catégories neutres, `defective_product`, `merchant_error`, `commercial_gesture` et `other`, ne sont jamais soumises à l'autorisation de remboursement. Une réclamation de colis (`item_not_received`, `empty_package_claim`, `missing_items_claim`, `return_not_received_dispute`) n'est contrôlée que si le marchand la choisit : par la table des codes, ou comme catégorie d'une des quatre situations. Sur Magento Open Source, un remboursement de retour est reconnu comme tel quand l'extension de retours appelle `ReturnReporterInterface`, par la table des codes, ou quand `returnsentry/mapping/refund_shipped` vaut `return` ; sinon il vaut `other` et n'est pas soumis. Le mode Observation montre ce qui serait contrôlé : vérifier ces correspondances avant de passer en Application.

### 3.3 Beacon et consentement (front Magento)

Avec `returnsentry/features/beacon` à `1` et la vue magasin connectée, le module ajoute `<adresse ReturnSentry>/beacon.js` avec la clé publique à toutes les pages du front, et l'adresse de ReturnSentry à la politique de sécurité du contenu (CSP) de Magento. Il déclare le type de page (`home`, `listing`, `product`, `cart`, `checkout`, `account`, `other`), avec les étapes `shipping` et `payment` du tunnel Luma et l'étape `confirmation` sur la page de succès, et signale le passage au paiement, la connexion (formulaire Luma `login-form`) et l'ajout au panier (événement jQuery `ajax:addToCart` de Luma).

Sur Hyvä, le script se charge et fonctionne sans RequireJS ni jQuery ; l'ajout au panier, les étapes `shipping` et `payment` et la connexion par le formulaire Hyvä ne sont pas signalés. Ces signaux nourrissent l'étude du comportement de navigation, un réglage facultatif de la console, et non le score. Le passage au paiement, qui nourrit le score, est signalé sur toute page `checkout_index_index`, quel que soit le thème.

Le chargement suit `returnsentry/beacon/loading` :

- `consent` : le script se charge après le consentement, et sans lui sur les pages `customer_account_login` et `customer_account_create`, où il sécurise l'authentification. Le consentement appelle aussi `ReturnSentry.consent(true)`.
- `always` : le script se charge partout ; `ReturnSentry.consent(true)` n'est appelé qu'avec le consentement. Ce que ReturnSentry observe sans consentement n'entre dans aucune décision de retour, de remboursement ou de réclamation.

Le consentement arrive de deux façons, cumulables :

- **Cookie** : le cookie `returnsentry/beacon/consent_cookie` contient `returnsentry/beacon/consent_cookie_value` comme élément entier entre les séparateurs `,` `;` `|` `&` ou des espaces, ou comme valeur d'un élément `nom=valeur`. Un nom seul, comme `C0004`, vaut pour `C0004:1`, `C0004=1` ou `C0004:true`, jamais pour `C0004:0`. Exemples : cookie `axeptio_authorized_vendors` et valeur `returnsentry` pour Axeptio ; cookie `OptanonConsent` et valeur `C0004:1` pour OneTrust. Didomi encode son cookie : passer par l'événement. Le cookie est lu une fois par page : un consentement ou un retrait fait sur la page vaut à la page suivante. Un cookie qui ne se décode pas (séquence `%` invalide) est lu tel quel.
- **Événement** sur `window`, nommé par `returnsentry/beacon/consent_event` : `detail: true` donne le consentement ; toute autre valeur le retire.

Quand le consentement est constaté, le script pose le cookie de session `rs_consent=1`. Il le retire à un événement de retrait, ou sur une page où le cookie de consentement configuré est présent et ne donne plus le consentement ; il efface alors l'identifiant visiteur de l'appareil (`rs_vid`, cookie et `localStorage`), sans charger le beacon, sauf sur une page qui charge le beacon sans consentement (pages de connexion et de création de compte, et toutes les pages avec `always`), puis appelle `ReturnSentry.consent(false)`.

Branchements, à placer dans la balise du gestionnaire de consentement, ReturnSentry étant déclaré comme fournisseur ou finalité « lutte contre la fraude » :

```js
// Didomi
window.didomiOnReady = window.didomiOnReady || [];
window.didomiOnReady.push(function (Didomi) {
  var send = function () {
    window.dispatchEvent(new CustomEvent('returnsentry:consent', {
      detail: Didomi.getUserStatusForVendor('<id fournisseur ReturnSentry>') === true
    }));
  };
  send();
  Didomi.on('consent.changed', send);
});

// OneTrust : la catégorie C0004, ou celle choisie, dans les groupes actifs
function OptanonWrapper() {
  window.dispatchEvent(new CustomEvent('returnsentry:consent', {
    detail: (',' + window.OnetrustActiveGroups + ',').indexOf(',C0004,') > -1
  }));
}

// Axeptio
window._axcb = window._axcb || [];
window._axcb.push(function (sdk) {
  sdk.on('cookies:complete', function (choices) {
    window.dispatchEvent(new CustomEvent('returnsentry:consent', { detail: !!choices.returnsentry }));
  });
});
```

Le serveur lit l'identifiant visiteur (cookie `rs_vid`) au paiement et au passage de la commande seulement avec un signe de consentement : `rs_consent=1`, le cookie de consentement configuré, ou l'en-tête `X-ReturnSentry-Consent: 1` (section 4) ; avec `always`, sans condition. Un identifiant hors format (8 à 100 caractères parmi `A-Z`, `a-z`, `0-9`, `_` et `-`) est ignoré. ReturnSentry ne relie un appareil à un client que s'il l'a vu avec le consentement du visiteur.

### 3.4 Pseudonymisation

Avec `returnsentry/privacy/pseudonymize` à `1`, le module envoie l'email haché (`<jeton>@pseudo.invalid`), des empreintes de l'adresse et du téléphone, le seul pays de l'adresse, et aucune adresse IP. L'identifiant visiteur et l'agent utilisateur partent tels quels, et le beacon parle directement à ReturnSentry. Les calculs sont ceux de `tools/magento-export` : avec le même secret, l'historique exporté et le direct désignent les mêmes clients. La pseudonymisation ne s'active qu'avec un secret de 32 caractères au moins ; un secret raccourci ensuite déconnecte les vues magasin du site web. Le secret ne doit plus changer une fois des données envoyées. La console affiche alors des jetons au lieu des emails.

### 3.5 Mise en service : observer, puis appliquer

Les décisions au paiement et l'autorisation de remboursement démarrent en `observe` : le module appelle ReturnSentry, garde la décision, que la commande affiche sous la forme « aurait : … », et n'applique rien, même en cas de délai ou d'erreur. Le module envoie le mode avec chaque évaluation au paiement et chaque autorisation de remboursement ; la console montre, pour chaque point, observation, application ou application partielle, d'après les appels des dernières 24 heures.

1. Brancher ReturnSentry (section 2.3), en laissant les deux points en `observe`.
2. Reprendre l'historique (section 6.3).
3. Régler les correspondances (section 3.2) et le consentement (section 3.3), puis comparer les décisions observées aux commandes et aux avoirs.
4. Passer en application, vue magasin par vue magasin :

```bash
bin/magento config:set --scope=stores --scope-code=<code de la vue magasin> returnsentry/features/checkout_decisions enforce
bin/magento config:set --scope=stores --scope-code=<code de la vue magasin> returnsentry/features/refund_authorization enforce
bin/magento cache:flush
```

5. Pour masquer des moyens de paiement sur `restrict_payment`, les nommer :

```bash
bin/magento config:set returnsentry/checkout/risky_payment_methods cashondelivery
```

Une décision gardée en Observation compte comme dernière décision d'un remboursement une fois en Application (section 6.6).

### 3.6 Profils de marchand

Un profil est un fichier JSON qui porte les réglages propres à un marchand, gardé hors du module : correspondances, moyens de paiement, vues magasin laissées éteintes.

```json
{
  "default": {
    "checkout/risky_payment_methods": "cashondelivery",
    "mapping/refund_code_field": "reason_code",
    "mapping/refund_codes": { "c1": { "shop": "CTR001", "standard": "item_not_received" } }
  },
  "websites": { "<code du site web>": { "privacy/pseudonymize": "1" } },
  "stores": { "<code de la vue magasin>": { "general/enabled": "0" } }
}
```

```bash
bin/magento returnsentry:profile <fichier.json>
```

- Les clés sont les chemins de la section 3.1 sans `returnsentry/`. Les valeurs sont des chaînes, comme dans `config.xml` (`"1"`, `"observe"`, codes séparés par des virgules) ; les tables `mapping/return_reasons` et `mapping/refund_codes` sont des objets de lignes `{"shop": …, "standard": …}`.
- Tout le fichier est vérifié avant le premier enregistrement, avec les règles de l'administration : chemin connu et affiché à cette portée, valeur prise dans les options du champ (moyens de paiement installés compris), motif ou catégorie standard connu, valeur de boutique non vide. Chaque portée s'enregistre ensuite d'un bloc par le modèle d'enregistrement de l'administration, qui ajoute ses propres contrôles : pseudonymisation refusée sans secret de 32 caractères, par exemple. Un chemin verrouillé dans `app/etc/env.php` ou `config.php` garde sa valeur, et la commande le nomme.
- Un profil ne porte jamais l'adresse, les clés ni le secret de pseudonymisation, qui passent par `returnsentry:connect` (section 2.3), et ne peut qu'éteindre le module (`general/enabled` à `0`), jamais l'allumer.
- Un réglage absent du profil garde sa valeur. N'y mettre que ce qui diffère des défauts : un `observe` écrit dans le profil ramènerait en Observation, à chaque application, un point passé en Application.

## 4. Boutique découplée (GraphQL, REST)

Un storefront Nuxt, Next ou PWA qui appelle Magento depuis son serveur reçoit les mêmes décisions que Luma : le module s'appuie sur les services de Magento que GraphQL et REST appellent.

- **Évaluation au paiement** : `placeOrder` (GraphQL) ; `POST /V1/carts/mine/payment-information`, `PUT /V1/carts/mine/order` et leurs variantes `guest-carts` (REST). Un refus revient en erreur de l'appel, avec le message neutre de la section 6.4, dans la langue de la vue magasin. Seuls les canaux du client sont évalués : jeton client, panier invité, session.
- **Moyens de paiement** : `available_payment_methods` du panier (GraphQL), `GET /V1/carts/mine/payment-methods` et la réponse de `POST /V1/carts/mine/shipping-information` (REST).
- **Retours Adobe Commerce** : `requestReturn`, `requestGuestReturn` et `addReturnTracking` (GraphQL), `POST /V1/returns`, `PUT /V1/returns/:id` et `POST /V1/returns/:id/tracking-numbers` (REST) sont synchronisés et évalués comme dans l'administration.
- **Autorisation de remboursement et synchronisation** : identiques quel que soit le canal.

Le storefront a trois choses à faire : charger le beacon, transmettre l'identifiant visiteur et afficher les conditions de retour.

### 4.1 Beacon

Le storefront charge le script avec la clé publique de son site web (`returnsentry/general/publishable_key`) :

```html
<script src="https://<adresse ReturnSentry>/beacon.js" data-key="rs_pk_..." async></script>
```

Il suit `packages/beacon/README.md` du dépôt ReturnSentry : dans l'Union européenne, charger le script après le consentement, sauf sur les écrans de connexion et de création de compte, qui appellent `ReturnSentry.exempt()` ; appeler `ReturnSentry.consent(true)` ou `ReturnSentry.consent(false)` depuis le gestionnaire de consentement ; déclarer chaque page par `ReturnSentry.page(...)` après chaque changement de route ; signaler `add_to_cart`, `login` et `checkout` par `ReturnSentry.track(...)`. La CSP du storefront autorise l'adresse de ReturnSentry dans `script-src` et `connect-src`. Pour un storefront qui appelle Magento depuis son serveur, seul `returnsentry/beacon/loading` agit parmi les réglages de la section 3.3 : à `always`, l'identifiant est lu sans consentement.

### 4.2 Identifiant visiteur

Les cookies du visiteur n'arrivent pas à Magento quand le storefront l'appelle depuis son serveur. Le storefront envoie deux en-têtes avec les appels faits pour ce visiteur, au moins ceux qui listent les moyens de paiement et celui qui passe la commande :

| En-tête | Valeur |
|---|---|
| `X-ReturnSentry-Visitor-Id` | L'identifiant visiteur : le cookie `rs_vid` que le beacon pose sur le domaine du storefront, ou `ReturnSentry.visitorId()` dans le navigateur. |
| `X-ReturnSentry-Consent` | `1`, seulement quand le visiteur a consenti à la finalité « lutte contre la fraude ». |

L'en-tête l'emporte sur le cookie `rs_vid`. Sans `X-ReturnSentry-Consent: 1`, l'identifiant n'est ni lu ni envoyé, sauf avec `returnsentry/beacon/loading` à `always` ; un identifiant hors format est ignoré. Un storefront qui appelle Magento depuis le navigateur, sur le même site, peut s'en tenir aux cookies de la section 3.3. Si le storefront n'envoie les en-têtes qu'au passage de la commande, les évaluations précédentes partent sans identifiant et le passage de la commande appelle ReturnSentry une seconde fois.

GraphQL, depuis le serveur du storefront :

```js
const returnSentry = consented && visitorId
  ? { 'X-ReturnSentry-Visitor-Id': visitorId, 'X-ReturnSentry-Consent': '1' }
  : {};

await fetch('https://magento.example.com/graphql', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Store: 'fr_fr', ...returnSentry },
  body: JSON.stringify({
    query: 'mutation ($cart: String!) { placeOrder(input: { cart_id: $cart }) { orderV2 { number } } }',
    variables: { cart: cartId },
  }),
});
```

REST :

```bash
curl -X POST 'https://magento.example.com/rest/fr_fr/V1/guest-carts/<cartId>/payment-information' \
  -H 'Content-Type: application/json' \
  -H 'X-ReturnSentry-Visitor-Id: k3Jd9QxW2bL0pZ7aR4tY1g' \
  -H 'X-ReturnSentry-Consent: 1' \
  -d '{"email": "client@example.com", "paymentMethod": {"method": "checkmo"}}'
```

### 4.3 Conditions de retour

Avec `returnsentry/features/checkout_decisions` à `enforce`, le storefront lit les conditions de retour du panier une fois l'email et l'adresse du client enregistrés (`setGuestEmailOnCart` en GraphQL ; en REST, l'email d'un invité arrive par `POST /V1/guest-carts/:cartId/set-payment-information`), et de nouveau quand le panier change. Hors Application, les conditions valent `paid: false`.

```graphql
query ($cart: String!) {
  cart(cart_id: $cart) {
    returnsentry_return_terms { paid label_cost }
  }
}
```

```bash
curl 'https://magento.example.com/rest/fr_fr/V1/guest-carts/<cartId>/returnsentry-return-terms'
```

Réponse : `{"paid":true,"label_cost":6.5}`. Pour un client connecté : `GET /V1/carts/mine/returnsentry-return-terms`, avec son jeton. `label_cost` est dans la devise de base du site web ; sans coût d'étiquette, il vaut `null` en GraphQL et manque en REST.

Quand `paid` vaut `true`, le storefront affiche, avant le bouton de commande, que le renvoi des articles est à la charge du client et, avec `label_cost`, que ce coût est déduit du remboursement. Puis il envoie `X-ReturnSentry-Return-Terms-Displayed: 1` avec l'appel qui passe la commande, seulement s'il les a affichées. Le module ne déclare les conditions affichées que si ce panier a reçu des conditions payantes par ce champ ou cette route ; sans l'en-tête, les retours de la commande restent gratuits. Un storefront qui envoie sa propre confirmation de commande y rappelle les conditions affichées (section 5).

### 4.4 Adresse IP

La commande et l'évaluation au paiement portent l'adresse IP que voit Magento. Derrière un storefront, c'est celle de son serveur, sauf si le storefront transmet l'adresse du visiteur (`X-Forwarded-For`) et que Magento la lit, par les `alternativeHeaders` de `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress`.

## 5. Obligations légales du marchand

Le module applique des décisions ; leur cadre juridique reste celui du marchand. Référence complète : `docs/legal.md` du dépôt ReturnSentry. Ce n'est pas un avis juridique.

- **Conditions de retour annoncées avant la commande.** Un `paid_return` ou un `statutory_only` ne fait payer le renvoi que si les conditions de retour du client étaient affichées avant la validation de la commande (C. conso. L221-5 8°, L221-23). En Application, le module les affiche sur Luma au-dessus des moyens de paiement quand elles sont payantes, pose le cookie de session `rs_return_terms=1`, et déclare à ReturnSentry, au passage de la commande seulement, qu'elles ont été affichées (`returnTermsDisplayed`). Un autre tunnel les affiche lui-même, puis le déclare par le cookie `rs_return_terms=1` (front Magento) ou l'en-tête `X-ReturnSentry-Return-Terms-Displayed: 1` (storefront découplé), et seulement s'il les a réellement affichées. Sans cette déclaration, les retours de la commande restent gratuits : ReturnSentry répond `allow` avec la raison `return_terms_not_announced`. Un client invité n'est connu par son email qu'au passage de la commande : sur Luma, un invité que le panier ne permet pas de reconnaître (compte, téléphone, identifiant visiteur) ne voit pas de conditions payantes, et ses retours restent gratuits. Une bannière « retours gratuits » porte sa réserve. Seul le coût direct de l'étiquette peut être facturé.
- **Rappel dans la confirmation.** La confirmation de commande reprend ces conditions (L221-13). Le module garde les conditions payantes annoncées sur la commande (`returnsentry_return_paid`, `returnsentry_return_label_cost`) et fournit le bloc d'email `returnsentry_email_return_terms`, qui n'affiche rien quand les retours sont gratuits. Dès que des retours payants sont décidés, ajouter cette ligne, à côté de la liste des articles, aux modèles de confirmation de commande pour le client et pour l'invité (Marketing > Communications > Modèles d'email ; modèles choisis dans Magasins > Configuration > Ventes > Emails de vente > Commande) :

  ```
  {{layout handle="returnsentry_email_return_terms" order_id=$order_id area="frontend"}}
  ```

  Une confirmation envoyée hors de Magento (outil d'emailing, storefront découplé) rappelle elle-même les conditions affichées.
- **Consentement.** En France et dans l'Union européenne, lire le terminal hors connexion et création de compte exige le consentement (loi Informatique et Libertés, art. 82) : garder `returnsentry/beacon/loading` à `consent` et brancher le gestionnaire de consentement, par le cookie ou l'événement (section 3.3). Sur un storefront découplé, n'envoyer `X-ReturnSentry-Consent: 1` qu'avec ce consentement. La politique de confidentialité mentionne ces traceurs.
- **Rétractation.** Aucune décision ne retire le droit de rétractation de 14 jours ni ne conditionne le bouton « renoncer au contrat » ; le module ne bloque aucun retour.
- **Remboursements.** Refuser ou réduire un remboursement reste une décision humaine, prise dans la console. Une attente sans preuve de fraude se lève à l'échéance légale pour un retour, et après le délai d'attente du transporteur pour une réclamation de colis, compté depuis `claimedAt`. Le message d'arrêt donne à l'administrateur, quand ReturnSentry en fournit un, le lien de preuve à transmettre au client.
- **Paiement.** Masquer un moyen de paiement repose sur des critères de comportement, garde toujours au moins un moyen et s'annonce au client avant la commande. Un refus de commande (`block`) ne vient que d'une décision d'opérateur.
- **Données personnelles.** Contrat de sous-traitance avec l'opérateur de ReturnSentry, analyse d'impact, information des clients (clause 7.12 de `docs/legal.md`). Les données que le module garde dans Magento sont décrites dans `docs/legal.md`, section 2.14. Supprimer un compte client dans Magento l'efface chez ReturnSentry ; pour un client sans compte, l'effacement passe par `DELETE /v1/customers?email=…` (le jeton pseudonyme quand la pseudonymisation est active) ou par `rs customer:erase`.

## 6. Exploitation

### 6.1 File et cron

| Élément | Nom | Rôle |
|---|---|---|
| Sujet et consommateur | `returnsentry.sync` | Envoie chaque enregistrement publié par les observateurs. File MySQL par défaut, AMQP quand `env.php` le configure. |
| Tâche cron | `returnsentry_sync_retry`, groupe `returnsentry`, chaque minute | Rejoue les envois échoués, envoie les effacements, les dérogations et les contrôles, 200 lignes par passage. |
| Tâche cron | `returnsentry_apply_retention`, groupe `returnsentry`, chaque jour à 03:17 | Applique les durées de conservation (section 6.7). |
| Table | `returnsentry_sync_retry` | Envois en attente d'un nouvel essai. |
| Table | `returnsentry_inspection` | Contrôles déclarés, jusqu'à ce que ReturnSentry les reçoive ou les refuse pour leur contenu, ou jusqu'à la nuit qui suit l'abandon de leur envoi. |

```bash
bin/magento queue:consumers:start returnsentry.sync
bin/magento cron:run --group=returnsentry
```

### 6.2 Synchronisation

L'observateur publie un message ; le consommateur relit l'entité et l'envoie. Un envoi répété met la ligne à jour chez ReturnSentry.

| Événement Magento | Envoi | Identifiant (`externalId`) |
|---|---|---|
| `customer_save_after_data_object` (enregistrement par le dépôt de clients) | `POST /v1/customers` | `m2:<id client>` |
| `sales_order_save_commit_after` | `POST /v1/orders` | numéro de commande |
| `sales_order_shipment_track_save_commit_after` | `POST /v1/shipments`, un envoi par numéro de suivi | transporteur et numéro de suivi |
| `sales_order_creditmemo_save_commit_after` | `POST /v1/refunds` | `cm:<numéro d'avoir>` |
| `rma_save_commit_after`, et `core_abstract_save_commit_after` pour un numéro de suivi de RMA | `POST /v1/returns` | `rma:<numéro de RMA>` |
| `ReturnReporterInterface::report()` | `POST /v1/returns` | celui de l'appelant |
| `customer_delete_commit_after` | `DELETE /v1/customers?externalId=m2:<id client>` | `m2:<id client>` |

- **Commande** : articles avec leur poids unitaire du catalogue, montants dans la devise de base (`paidAmount` et `currency` dans la devise de la commande), pays et adresse de livraison ou leurs empreintes, moyen de paiement, descripteur de carte quand le prestataire renseigne les quatre derniers chiffres, identifiant visiteur capté au passage de la commande. Une commande `pending_payment` ou `payment_review` part quand le paiement est confirmé.
- **Colis** : chaque numéro de suivi devient un colis aller. Le transporteur est ramené à son code standard (`colissimo`, `chronopost`, `dpd_fr`, `ups`…) d'après son code Magento, puis son libellé, `other` sinon. Une expédition à un seul numéro de suivi y joint ses articles et son poids total ; avec plusieurs numéros, ni l'un ni l'autre. Les poids sont convertis en grammes depuis `general/locale/weight_unit` ; un poids nul, absent, d'unité inconnue ou de plus de 1 000 kg n'est pas envoyé.
- **Retour** : il porte ses numéros de suivi (20 au plus), avec le transporteur ramené de la même façon.
- **Identifiants** : ceux de `tools/magento-export`, pour que l'historique importé et le direct désignent les mêmes clients et commandes. L'export pseudonymise toujours : un client invité ne correspond que si le module est pseudonymisé avec le même secret.
- **Erreurs** : une erreur passagère (réseau, délai, HTTP 429 ou 5xx, réponse illisible) ou une clé secrète refusée (401, 403 : clé révoquée, erronée ou en cours de remplacement) programme un nouvel essai après 1, 2, 4, 8… minutes, jusqu'à 6 heures d'écart, 20 fois au plus, soit environ trois jours ; au-delà, l'envoi est abandonné et journalisé. Une erreur définitive (tout autre refus HTTP 4xx : données refusées, adresse fausse, 404) est journalisée sans nouvel essai : l'envoi est abandonné, pas mis en file. Après correction de l'adresse, ou d'une clé restée refusée plus de trois jours, rattraper la période par la reprise de l'historique (`bin/magento returnsentry:backfill --from=<date du premier refus>`). Un effacement est essayé jusqu'à ce que ReturnSentry le confirme, une dérogation jusqu'à ce qu'il l'accepte ou la refuse.
- **Boutique déconnectée** : un événement survenu pendant qu'une vue magasin n'est pas connectée n'est pas mis en file. Le rattraper par la reprise de l'historique.

### 6.3 Reprise de l'historique

```bash
bin/magento returnsentry:backfill --from=2024-01-01 --to=2026-09-01 --dry-run
bin/magento returnsentry:backfill --from=2024-01-01 --to=2026-09-01
bin/magento returnsentry:backfill --from=2024-01-01 --store=fr_fr
```

- `--from` est obligatoire et inclus, `--to` exclu (demain par défaut) ; dates `AAAA-MM-JJ` en UTC. `--store` prend un code de vue magasin ; par défaut, toutes les vues connectées. `--dry-run` compte sans envoyer, y compris sur une vue non connectée.
- La commande envoie, par lots de 500 sur `POST /v1/batch`, les clients des commandes de la période, les commandes, leurs colis, leurs retours et leurs avoirs. Elle saute les commandes en attente de paiement et celles d'un client effacé. Elle n'évalue aucun retour et ne demande aucune autorisation de remboursement.
- Chaque enregistrement refusé est affiché avec son lot, sa position, son type, sa référence Magento et la raison. Un lot en échec (réseau, 5xx, délai de 120 s) arrête cette vue magasin, avec un code de sortie d'échec ; les autres vues continuent. Les envois étant idempotents, relancer la même période.
- Filet de sécurité contre les envois perdus, dans la crontab du serveur (GNU `date`) :

```bash
30 3 * * * cd /var/www/magento && bin/magento returnsentry:backfill --from=$(date -u -d '3 days ago' +\%F)
```

### 6.4 Évaluation au paiement

**Quand.** Au passage de la commande, l'observateur `returnsentry_apply_checkout_decision` de `sales_model_service_quote_submit_before` évalue le panier (`POST /v1/assessments`, contexte `checkout`). En Application, le panier est aussi évalué quand Magento liste les moyens de paiement, si au moins un moyen à risque est configuré, et quand les conditions de retour sont lues (section 4.3, avis Luma). Ne sont pas évalués : les paniers de l'administration ou d'un jeton d'administrateur ou d'intégration, le passage d'une commande payée par un moyen de `returnsentry/features/excluded_payment_methods`, un panier à expédier sans pays de livraison, un panier sans référence client ni identifiant visiteur.

**Checkout externe.** Un checkout externe comme Global-e crée ses commandes de serveur à serveur par une route REST anonyme (`POST /V1/international/order/create`, moyen de paiement `globale`) et ses remboursements par `POST /V1/international/order/refund`. Magento donne à tout appel REST anonyme le type d'utilisateur invité (`UserContextInterface::USER_TYPE_GUEST`), celui d'un client qui paie sur un front découplé : le canal ne les distingue pas. Exclure le moyen de paiement du checkout externe : le passage de ses commandes n'est ni évalué ni arrêté, et ses avoirs passent sans autorisation, ce qui laisse son flux de remboursement intact. Seule la liste des moyens de paiement reste évaluée et filtrée comme pour tout panier : Magento la calcule dès que le mode de livraison est choisi (`shipping-information` en REST, `setShippingMethodsOnCart` en GraphQL), avant tout moyen de paiement, quand rien ne dit encore que la commande sera payée par un moyen exclu. Le module exclut `globale` par défaut ; pour un autre checkout externe, ajouter son code :

```bash
bin/magento config:set returnsentry/features/excluded_payment_methods globale,<code du moyen>
```

**Contenu.** Contexte `checkout`, mode, `returnTermsDisplayed` (`true` seulement au passage de la commande, section 5), client, appareil (identifiant visiteur avec consentement, adresse IP sauf pseudonymisation, agent utilisateur), commande (numéro réservé, montant et articles dans la devise de base, adresse de livraison). Le numéro de commande est réservé à la première évaluation du panier : un panier abandonné laisse un numéro inutilisé.

**Réponse gardée.** 10 minutes pour ce panier et ce contenu, une minute après un échec. Le passage de la commande reprend la réponse quand rien n'a changé ; il appelle de nouveau ReturnSentry quand l'email d'un invité n'arrive qu'à ce moment, ou quand des conditions de retour payantes ont été affichées.

**Délai ou erreur.** Au-delà de `returnsentry/general/checkout_timeout_ms`, ou sur une erreur, la commande passe comme sur `allow` et l'incident est journalisé ; une décision d'opérateur renvoyée avec `failOpen: true` s'applique quand même.

| Action | Application | Observation |
|---|---|---|
| `allow` | Rien. | Rien. |
| `review` | La commande passe, avec le commentaire « ReturnSentry recommande une revue manuelle de cette commande (niveau …, score …). Raisons : … » et « À revoir » dans la colonne ReturnSentry de la grille. | Gardée sur la commande, « aurait : À revoir ». |
| `restrict_payment` | Les moyens de `returnsentry/checkout/risky_payment_methods` sont masqués, sauf s'ils sont les seuls disponibles. Un moyen masqué envoyé directement est refusé : « Ce moyen de paiement n'est pas disponible pour cette commande. Merci d'en choisir un autre. » | Gardée, sans effet. |
| `block` | Commande refusée : « Nous ne pouvons pas finaliser cette commande. Merci de contacter notre service client. » ReturnSentry ne produit `block` que sur décision d'un opérateur. | Gardée, sans effet. |

La commande garde `returnsentry_level` et `returnsentry_score`, et `returnsentry_action` en Application ou `returnsentry_observed_action` en Observation.

### 6.5 Évaluation des retours

Chaque nouveau retour est envoyé, puis évalué par le consommateur (`POST /v1/assessments`, contexte `return`), et évalué de nouveau quand sa date de réception (`goodsBackAt`) arrive. La décision est gardée sur le retour et affichée dans le panneau de la commande ; le module ne l'applique jamais. Une évaluation sans réponse n'est pas gardée ; elle est refaite au prochain envoi du retour.

| Action | Signification affichée |
|---|---|
| `allow` | Retour aux conditions habituelles de la boutique. |
| `paid_return` | Le client paie le coût direct de l'étiquette, si cela a été annoncé avant la commande. |
| `inspect_before_refund` | Rembourser après le contrôle du colis, dans le délai légal. |
| `statutory_only` | Rétractation légale seulement, toujours acceptée : 14 jours, renvoi à ses frais, contrôle avant remboursement. Sur une commande sans conditions payantes annoncées : « …14 jours, renvoi gratuit, contrôle avant remboursement. » |

`paid_return` et `statutory_only` ne viennent que d'une décision d'opérateur ; le renvoi n'est à la charge du client que si des conditions payantes ont été annoncées pour la commande (section 5), ce que lit la signification affichée (`returnsentry_return_paid`).

**Adobe Commerce.** Un RMA est envoyé et évalué juste après sa création, puis de nouveau au premier passage à un statut reçu de son historique. Statuts : `pending`, `unconfirmed`, `authorized`, `partially_authorized` → `requested` ; `received`, `received_on_item` → `received` ; `approved`, `approved_on_item` → `received` ; `processed_closed` → `refunded` ; `rejected`, `rejected_on_item`, `denied` → `rejected` ; `closed` → `cancelled`. Un RMA est toujours un retour client.

**Magento Open Source et Mage-OS.** Une extension de retours, ou le code du marchand, déclare chaque retour par `ReturnSentry\Connector\Api\ReturnReporterInterface`, à la création et à chaque changement de statut :

```php
use ReturnSentry\Connector\Api\Data\ReturnItem;
use ReturnSentry\Connector\Api\Data\ReturnRequest;
use ReturnSentry\Connector\Api\Data\ReturnShipment;
use ReturnSentry\Connector\Api\ReturnReporterInterface;

public function __construct(private readonly ReturnReporterInterface $returnSentry)
{
}

$assessment = $this->returnSentry->report(new ReturnRequest(
    externalId: 'RET-000123',
    orderId: (int) $order->getEntityId(),
    requestedAt: $returnRequestedAt,
    items: [new ReturnItem(orderItemId: (int) $orderItem->getItemId(), quantity: 1, reason: 'Trop petit')],
    status: 'received',
    receivedAt: $parcelReceivedAt,
    shipments: [new ReturnShipment(trackingNumber: '8R00123456789', carrier: 'colissimo')],
));
```

| Paramètre | Sens |
|---|---|
| `externalId` | Identifiant du retour, 200 caractères au plus, unique parmi tous les retours. |
| `orderId` | `entity_id` de la commande. |
| `requestedAt` | Date de la demande de retour, soit la déclaration de rétractation. Toujours la même d'un envoi à l'autre : elle fait courir le délai de remboursement. |
| `items` | Lignes de commande retournées, quantité, et `reason` : un libellé de la boutique (table des motifs) ou un motif standard. |
| `status` | `requested`, `in_transit`, `received`, `refunded`, `rejected` ou `cancelled`. |
| `reason` | Motif du retour entier. `return_to_sender` déclare un colis que le transporteur a ramené sans que le client l'ait retiré ; il est ignoré sur une commande payée dans une autre devise que la devise de base. |
| `receivedAt` | Date de réception du colis. Un nouveau `receivedAt` déclenche une nouvelle évaluation ; cette date borne l'attente d'un remboursement. |
| `shipments` | Colis retour : numéro de suivi, code ou libellé du transporteur. |

`report()` écrit le retour, le met en file et rend la décision déjà gardée (`action`, `level`, `score`, `reasons`), ou `assessed: false` avec l'action `allow` tant qu'elle n'existe pas. Il lève `LocalizedException` pour un identifiant vide ou un statut inconnu, et `NoSuchEntityException` pour une commande inconnue.

**Contrôles de l'entrepôt.** Le code du marchand qui lit le fichier ou les appels de son entrepôt déclare chaque contrôle par `ReturnSentry\Connector\Api\InspectionReporterInterface`. Le contrôle est rattaché au retour que le module a enregistré : `rma:<numéro du RMA>` pour un RMA d'Adobe Commerce, connu du module après son premier envoi, ou l'`externalId` donné à `ReturnReporterInterface`. Il est gardé dans `returnsentry_inspection` et envoyé par la tâche cron à `POST /v1/inspections`. Une erreur passagère, ou une clé ou une adresse refusée (401, 403, 404), est réessayée, 20 fois au plus, pendant environ trois jours ; le contrôle est supprimé de Magento dès que ReturnSentry l'a reçu, ou l'a refusé pour son contenu. Un contrôle dont l'envoi est abandonné, après le vingtième essai ou faute d'adresse et de clé secrète sur la vue magasin, est supprimé par la tâche de conservation de la nuit suivante. Un contrôle nouveau fait redécider l'autorisation de remboursement à la demande suivante : un constat de la famille fraude met le remboursement en attente d'un opérateur.

```php
use ReturnSentry\Connector\Api\Data\InspectionItem;
use ReturnSentry\Connector\Api\Data\InspectionRequest;
use ReturnSentry\Connector\Api\InspectionReporterInterface;

public function __construct(private readonly InspectionReporterInterface $inspections)
{
}

$this->inspections->report(new InspectionRequest(
    externalId: 'WH-20260920-14',
    returnExternalId: 'rma:' . $rma->getIncrementId(),
    inspectedAt: $inspectedAt,
    items: [new InspectionItem(sku: 'DRESS-M', quantity: 1, outcome: 'wrong_item')],
    note: 'Ligne 14 du fichier',
    photoUrls: ['https://entrepot.example.com/photos/wh-20260920-14.jpg'],
));
```

| Paramètre | Sens |
|---|---|
| `externalId` | Identifiant du contrôle, 200 caractères au plus. Déclaré de nouveau avec le même identifiant, le contrôle remplace le précédent. |
| `returnExternalId` | Retour contrôlé, tel que le module l'a enregistré. |
| `inspectedAt` | Date du contrôle. |
| `items` | De 1 à 500 articles : SKU de 200 caractères au plus, quantité de 1 à 10 000, constat standard parmi `InspectionItem::OUTCOMES` (`docs/inspections-and-refunds.md` du dépôt ReturnSentry). |
| `note` | Note interne, 2 000 caractères au plus, jamais montrée au client. |
| `photoUrls` | Adresses `http` ou `https` des photos, 30 au plus, de 2 000 caractères au plus chacune. |

`report()` lève `LocalizedException` pour une demande que ReturnSentry refuserait, et `NoSuchEntityException` pour un retour que le module n'a pas enregistré. Pour un client effacé, il ne garde ni n'envoie rien.

**Codes de l'entrepôt.** Le marchand choisit le constat standard de chaque code de son entrepôt. Exemple pour un fichier qui code chaque article retourné de 1 à 4, à confirmer avec l'entrepôt :

| Code | Sens dans le fichier | Constat | À confirmer avec l'entrepôt |
|---|---|---|---|
| 1 | Article conforme | `conforming` | |
| 2 | Mauvais article | `wrong_item` | `substituted_object` si le code désigne un objet sans rapport (lest, autre produit) plutôt qu'un autre article. Les deux sont de la famille fraude. |
| 3 | Article cassé | `damaged_by_customer` | `transport_damage` si la casse vient du transport, `manufacturing_defect` d'un défaut de fabrication : ces deux constats sont neutres et laissent le remboursement accordé. |
| 4 | Article bloqué | aucun | Sens du code. En attendant, ne pas envoyer l'article ; un contrôle sans article est refusé. |

### 6.6 Autorisation de remboursement

**Quand.** Le plugin `returnsentry_authorize_refund` avant `Magento\Sales\Model\Order\RefundAdapterInterface::refund` interroge ReturnSentry (`POST /v1/refund-authorizations`) avant que l'argent ne sorte, pour chaque avoir créé dans l'administration ou par `POST /V1/order/:id/refund`, `POST /V1/invoice/:id/refund` ou `POST /V1/creditmemo/refund`. Ne sont pas soumis : les avoirs des catégories neutres (section 3.2), ceux d'une commande payée par un moyen exclu du contrôle (section 6.4), ceux d'un client effacé, et les remboursements partis du prestataire de paiement et seulement enregistrés dans Magento.

**Demande.** Identifiant `ra:<commande>:<ligne>x<quantité>…` (`adjustment` pour un avoir sans article ; condensé au-delà de 120 caractères ; `:2`, `:3` pour un avoir identique répété), retour rattaché (section 3.2), montant demandé (total de l'avoir plus les frais d'ajustement), date de la demande de retour (`withdrawnAt`), date de réception (`goodsBackAt` : premier statut reçu du RMA, ou `receivedAt` de `ReturnReporterInterface`), mode. Un avoir arrêté puis soumis de nouveau retrouve sa décision. Les décisions sont enregistrées même quand l'avoir est arrêté.

**Réclamations de colis.** Un avoir classé en réclamation porte sa catégorie et `claimedAt`, la date de la première demande qui a reçu une décision. Une réclamation sur le colis aller porte le numéro de suivi quand les articles de l'avoir ont voyagé dans un seul colis, et pour des articles manquants, les articles de l'avoir ; elle ne cite aucun retour. `return_not_received_dispute` cite son retour. Sans retour ouvert rattaché, l'avoir part comme un remboursement de retour, avec le client : ReturnSentry lui applique ses règles de retour (décisions d'opérateur, attente jusqu'à l'échéance légale pour un retour non contrôlé), la catégorie de réclamation exigeant un retour. Une réclamation sur le colis aller est décidée sur les faits du transporteur.

| Décision | Application |
|---|---|
| `approve` | L'avoir est créé. |
| `approve_partial` | L'avoir est arrêté avec le montant accordé et les frais d'ajustement à saisir ; soumis de nouveau avec cet ajustement, il passe. |
| `hold`, `deny` | L'avoir est arrêté avec les raisons, l'état du colis pour une réclamation, et, quand ReturnSentry les fournit, la date à partir de laquelle un nouvel avoir sera accordé et le lien de preuve. Le message renvoie à la décision dans la console (`/refunds?refund=<id>`), où un opérateur peut la changer. |

Par `POST /V1/order/:id/refund` et `POST /V1/invoice/:id/refund`, le message d'arrêt revient en erreur de l'appel.

**Remboursement lancé par du code.** Un arrêt lève `ReturnSentry\Connector\Exception\RefundStoppedException`, qui hérite de `Magento\Sales\Exception\CouldNotRefundException`. Le code qui appelle `RefundAdapterInterface::refund()`, `RefundOrderInterface::execute()` ou `RefundInvoiceInterface::execute()` reçoit cette exception elle-même ; `CreditmemoManagementInterface::refund()`, celui du formulaire de l'administration et de `POST /V1/creditmemo/refund`, la remplace par une `LocalizedException` au même message.

| Méthode | Valeur |
|---|---|
| `getMessage()` | Le message d'arrêt affiché dans l'administration. |
| `getDecision()` | `hold`, `deny` ou `approve_partial`. |
| `getReasons()` | Libellés des raisons de la décision ; vide quand ReturnSentry n'a pas répondu et que la décision enregistrée s'applique. |
| `getHoldUntil()` | Date ISO 8601 en UTC à partir de laquelle, sans preuve nouvelle, un nouvel avoir sera accordé ; `null` sans échéance. |
| `getEvidenceUrl()` | Lien de preuve à partager avec le client, ou `null`. |

Un flux automatique, comme l'import d'un fichier d'entrepôt qui rembourse les retours, l'attrape avant toute autre exception, passe l'article en revue manuelle et ne relance pas l'avoir : soumis de nouveau, il retrouve la même décision tant qu'un opérateur ne l'a pas changée dans la console, ou, pour une attente, jusqu'à `getHoldUntil()`. Un `catch (\Exception $e)` qui lève sa propre exception sans la précédente perd le type : attraper `RefundStoppedException` avant lui.

```php
use ReturnSentry\Connector\Exception\RefundStoppedException;

try {
    $order = $this->refundAdapter->refund($creditmemo, $order, $isOnline);
} catch (RefundStoppedException $stop) {
    $this->manualReview->add($returnLine, $stop->getDecision(), $stop->getReasons(), $stop->getHoldUntil());
    return;
}
```

**Sans réponse.** Délai, erreur réseau, erreur HTTP ou réponse dégradée : l'avoir passe, avec le commentaire « ReturnSentry n'a pas répondu : ce remboursement est passé sans contrôle. », une ligne de journal et la mention dans le panneau ; il part vers ReturnSentry par la synchronisation des avoirs. Il reste arrêté quand la dernière décision enregistrée pour ce remboursement (à défaut, pour une réclamation, la dernière de la commande) est un `hold` que ReturnSentry dit fondé sur une preuve de fraude (`fraudEvidence`) ou un `deny`, et quand la dernière décision de ce remboursement est un `approve_partial` dont l'avoir dépasse le montant accordé. Une décision gardée en Observation compte comme dernière décision.

**Clé ou adresse refusée.** Une réponse 401, 403 ou 404 laisse aussi passer l'avoir, pour qu'une erreur de configuration ne bloque aucun remboursement dû, et le signale : commentaire « ReturnSentry a refusé la clé secrète ou l'adresse : ce remboursement est passé sans contrôle. », mention « Remboursé sans contrôle, ReturnSentry a refusé la clé secrète ou l'adresse » dans le panneau, et message système « ReturnSentry refuse la clé secrète ou l'adresse de <site web> : le contrôle des remboursements est inactif. » Le message disparaît à la prochaine réponse de ReturnSentry à une autorisation de remboursement de ce site web, ou après un test de connexion réussi.

**Observation.** La décision est gardée et affichée (« aurait : … ») ; l'avoir est toujours créé. Le journal note seulement les décisions qui l'auraient arrêté.

**Dérogation.** Le droit « Rembourser malgré ReturnSentry » (ACL `ReturnSentry_Connector::refund_override`, Ventes > Opérations > Commandes > Actions > Avoirs) fait apparaître, sur le formulaire d'avoir de l'administration et en Application, une case et un motif de 3 caractères au moins. La case laisse passer un avoir arrêté ; la dérogation est enregistrée dans le module et dans l'historique de la commande (« Remboursé malgré ReturnSentry, par <administrateur>: <motif> »). La tâche cron l'envoie à `POST /v1/refund-authorizations/{externalId}/decision` (décision `approve`, motif, identifiant de l'administrateur), avec des essais sur les erreurs passagères et sur une clé secrète refusée (401, 403), toutes les 6 heures au plus, jusqu'à ce que ReturnSentry l'accepte ou la refuse. ReturnSentry accepte la dérogation d'une attente que la boutique a déjà remboursée. Une clé secrète refusée (401, 403) s'affiche aussitôt dans le panneau, « Pas encore transmis à ReturnSentry : la clé secrète a été refusée (HTTP …). Renvoyé dès que la clé est corrigée. », et la mention disparaît quand l'envoi passe. Tout autre refus est définitif : pour un 409, la dernière décision n'est plus en attente ni refusée (déjà accordée, en tout ou en partie), et le panneau indique « Non transmis à ReturnSentry : décision déjà définitive. » ; pour une autre erreur, « Non transmis à ReturnSentry : la dérogation a été refusée (HTTP …). Enregistrez-la dans la console. » Un avoir créé par REST se débloque dans la console.

### 6.7 Effacement et conservation

La suppression d'un client dans Magento (`customer_delete_commit_after`) garde une demande d'effacement dans `returnsentry_sync_retry`, envoyée par la tâche cron à `DELETE /v1/customers?externalId=m2:<id>` avec les clés du site web du client, jusqu'à confirmation, même module désactivé. Sans adresse ou sans clé secrète sur ce site web, rien n'est gardé : effacer le client chez ReturnSentry par `DELETE /v1/customers` ou `rs customer:erase`. Tant que des effacements attendent, un message système de l'administration les compte et cite la dernière erreur. Magento garde les commandes du client sans lien vers le compte : le module ne les envoie pas, ni leurs retours et avoirs, et ne demande pas d'autorisation de remboursement pour elles.

**Flux RGPD qui anonymise.** Un flux qui anonymise le client par des requêtes SQL, sans le supprimer, ne déclenche pas `customer_delete_commit_after`. `ReturnSentry\Connector\Api\CustomerErasureInterface::eraseByEmail()` est le point d'entrée de tout flux RGPD propre au marchand : il l'appelle **avant** d'anonymiser, avec l'email d'origine et le site web dont les clés ReturnSentry reçoivent l'effacement. Un flux qui ne connaît que l'email l'appelle pour chaque site web.

```php
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Api\CustomerErasureInterface;

public function __construct(
    private readonly CustomerErasureInterface $returnSentry,
    private readonly StoreManagerInterface $storeManager
) {
}

foreach ($this->storeManager->getWebsites() as $website) {
    $this->returnSentry->eraseByEmail($originalEmail, (int) $website->getId());
}
```

L'appel :

- garde dans `returnsentry_sync_retry` une demande d'effacement, envoyée par la tâche cron à `DELETE /v1/customers?email=…` avec les clés du site web, qui efface chez ReturnSentry tous les comptes à cet email, invités compris, avec les mêmes essais que pour une suppression. Avec la pseudonymisation, c'est le jeton de l'email qui part. L'email reste dans la demande jusqu'à la confirmation ;
- garde aussi, pour chaque compte Magento à cet email, la demande d'effacement par son identifiant (`m2:<id>`) qu'enverrait sa suppression : si l'anonymisation change l'email du compte par le référentiel des clients avant le départ de la demande par email, ce changement part chez ReturnSentry et la demande par email n'y trouve plus le compte, qui est tout de même effacé ;
- sur les commandes des vues magasin de ce site web passées avec cet email ou par un compte à cet email, efface l'identifiant visiteur, marque la commande (`returnsentry_erased`) pour que le module ne l'envoie plus, ni ses retours, contrôles et avoirs, et ne demande plus d'autorisation de remboursement pour elle, et supprime ses lignes de `returnsentry_return`, `returnsentry_refund_authorization` et `returnsentry_inspection`. Le niveau, le score, l'action au paiement et les conditions annoncées restent sur la commande avec ses autres champs, que l'anonymisation du marchand traite.

Il lève `LocalizedException` pour une valeur qui n'est pas une adresse email, et `NoSuchEntityException` pour un site web inconnu. Sans adresse ou sans clé secrète sur ce site web, il efface les données du module sans garder de demande.

**Conservation dans Magento.** La tâche `returnsentry_apply_retention` efface chaque nuit l'identifiant visiteur (`returnsentry_visitor_id`) des commandes créées il y a plus de 13 mois, la durée de conservation des traceurs que ReturnSentry applique aussi (`Retention::TRACKER_RETENTION_MONTHS`), et supprime les autorisations de remboursement dont la commande n'existe plus et les contrôles dont aucun envoi n'est plus en attente. Les retours enregistrés (`returnsentry_return`) partent avec leur commande, comme les contrôles pas encore envoyés (`returnsentry_inspection`). Le niveau, le score, l'action au paiement, les conditions de retour annoncées et le commentaire de revue font partie de la commande et suivent sa conservation.

### 6.8 Journal

`var/log/returnsentry.log` reçoit les échecs et les replis : évaluation au paiement sans réponse, autorisation de remboursement en échec ou dégradée, avoir passé sans contrôle, décision observée qui aurait arrêté un avoir, dérogation, envoi abandonné, message impossible à mettre en file. Les appels réussis ne sont pas journalisés. Le journal ne contient ni clé ni donnée de paiement.

### 6.9 Administration

- **Commande**, onglet Information (droit `Magento_Sales::actions_view`) : décision au paiement (« aurait : … » en Observation), conditions de retour payantes annoncées, risque actuel du client (niveau, score, raisons, lien vers sa fiche dans la console), décisions des retours avec leur signification, autorisations de remboursement (« aurait : … » en Observation), avoirs passés sans contrôle ou par dérogation. Le risque actuel est lu à l'affichage, dans le budget `returnsentry/general/checkout_timeout_ms`.
- **Client** : onglet « ReturnSentry », avec le risque actuel et le lien vers la console.
- **Grille des commandes** : colonne « ReturnSentry », filtrable, remplie en Application (« Autorisé », « À revoir », « Paiement restreint »).
- **Messages système** : effacements en attente ; clé secrète ou adresse refusée par ReturnSentry, contrôle des remboursements inactif.
- Les panneaux ne s'affichent que pour une vue magasin connectée.

## 7. Dépannage

| Symptôme | Cause | Vérification | Correction |
|---|---|---|---|
| Rien n'arrive chez ReturnSentry. | Vue magasin non connectée, ou consommateur arrêté. | `bin/magento config:show returnsentry/general/enabled`, bouton « Tester la connexion », `bin/magento queue:consumers:list`. | Brancher (section 2.3) ; lancer `bin/magento queue:consumers:start returnsentry.sync`. |
| Des commandes manquent après une coupure. | Événements survenus pendant la déconnexion, ou essais épuisés après environ trois jours. | `var/log/returnsentry.log` (« ReturnSentry sync failed »). | `bin/magento returnsentry:backfill --from=<date>`. |
| Les avoirs passent avec « ReturnSentry n'a pas répondu ». | ReturnSentry indisponible, ou plus lent que `returnsentry/general/timeout_ms`. | `var/log/returnsentry.log` (« refund authorization failed »). | Vérifier la disponibilité de ReturnSentry ; relever le budget. |
| Message système « ReturnSentry refuse la clé secrète ou l'adresse de … ». | Clé secrète révoquée ou erronée, ou adresse fausse : les avoirs passent sans contrôle. | Bouton « Tester la connexion ». | Corriger la clé ou l'adresse ; le message disparaît après un test réussi. Les envois de données refusés pour la clé sont rejoués pendant trois jours environ ; au-delà, ou pour une adresse fausse, `bin/magento returnsentry:backfill --from=<date du premier refus>`. |
| Un avoir est arrêté. | Décision `hold`, `deny` ou `approve_partial` en Application, ou, sans réponse de ReturnSentry, une décision enregistrée qui l'arrête (section 6.6). | Message d'arrêt, panneau de la commande. | Changer la décision dans la console, saisir l'ajustement pour `approve_partial`, ou déroger (section 6.6). |
| Le panneau ReturnSentry n'apparaît pas. | Vue magasin non connectée, ou droit `Magento_Sales::actions_view` absent. | Configuration de la portée de la commande. | Brancher la vue magasin ; accorder le droit. |
| Le beacon ne se charge pas. | `returnsentry/features/beacon` à `0`, clé publique vide ou `rs_sk_`, vue non connectée, consentement absent en mode `consent`, CSP d'un thème qui ignore celle de Magento. | Source de la page (`beacon.js`), console du navigateur. | Renseigner la clé publique, brancher le consentement (section 3.3). |
| Les commandes n'ont pas d'identifiant visiteur. | Pas de signe de consentement, cookie `rs_vid` absent, en-têtes manquants sur un storefront découplé, identifiant hors format. | Colonne `returnsentry_visitor_id` de `sales_order`. | Brancher le consentement ; envoyer les en-têtes de la section 4.2. |
| Un retour payant n'est pas appliqué (`return_terms_not_announced`). | Conditions non affichées ou non déclarées : Observation, invité non reconnu sur Luma, en-tête absent, tunnel sans avis. | Colonne `returnsentry_return_paid` de la commande. | Section 4.3 ou 5 ; le retour de cette commande reste gratuit. |
| La confirmation ne rappelle pas les conditions payantes. | Ligne du bloc absente du modèle d'email. | Modèle choisi dans Magasins > Configuration > Ventes > Emails de vente > Commande. | Ajouter la ligne de la section 5. |
| Un contrôle déclaré n'arrive pas chez ReturnSentry. | ReturnSentry injoignable, clé ou adresse refusée, ou contrôle refusé pour son contenu (journal « ReturnSentry sync failed »). | `SELECT entity_id, attempts, last_error FROM returnsentry_sync_retry WHERE entity_type = 'inspection';` et `var/log/returnsentry.log`. | Corriger l'adresse ou la clé dans les trois jours : la tâche cron le renvoie. Un contrôle refusé pour son contenu se déclare de nouveau, corrigé. |
| Le message système compte des effacements en attente. | ReturnSentry injoignable ou clé du site web refusée. | `SELECT entity_id, attempts, last_error FROM returnsentry_sync_retry WHERE entity_type = 'erasure';` | Corriger l'adresse ou la clé ; la tâche cron les renvoie. |
| La pseudonymisation refuse de s'activer. | Secret de moins de 32 caractères. | Message « Saisissez un secret de pseudonymisation d'au moins 32 caractères… ». | Enregistrer d'abord le secret, puis activer. |
| Le client voit « Nous ne pouvons pas finaliser cette commande ». | Décision d'opérateur `block` en Application. | Panneau du client dans la console. | Revoir la décision d'opérateur dans la console. |

## 8. Développement

Les dépendances de développement viennent du miroir public de Mage-OS (pas de clé Adobe) et de Packagist. `require-dev` fixe la version de Mage-OS (`mage-os/framework`) ; la monter est un changement délibéré, sans effet sur les boutiques.

```bash
cd integrations/magento2
composer install
composer check
```

`composer check` enchaîne `composer lint` (php -l), `composer phpcs` (norme Magento 2, `phpcs.xml.dist`), `composer phpstan` (niveau 6, avec `--memory-limit=2G` : un appel direct prend `vendor/bin/phpstan analyse --memory-limit=2G`, faute de quoi il s'arrête sur une erreur sans objet) et `composer test` (PHPUnit, suite `unit`). La CI du dépôt lance `composer check` à chaque pipeline (job `magento`, PHP 8.3 d'Alpine).

Aller-retour avec une vraie API ReturnSentry :

```bash
RS_E2E_URL=http://localhost:4000 RS_E2E_SECRET_KEY=rs_sk_... composer test:e2e
```

Pour vérifier le module dans une vraie boutique (Luma, GraphQL, REST, administration, file, cron, emails), suivre [`tools/magento-e2e/README.md`](../../tools/magento-e2e/README.md), depuis la racine du dépôt :

```bash
tools/magento-e2e/e2e.sh up && tools/magento-e2e/e2e.sh api
tools/magento-e2e/e2e.sh module --worktree && tools/magento-e2e/e2e.sh seed
tools/magento-e2e/e2e.sh test all
```

**Publication.** Après chaque pipeline vert sur `main`, le job `magento-module` de la CI publie un instantané du module dans le dépôt public `username-workspace/returnsentry-magento2` : les fichiers suivis de `integrations/magento2`, sans historique ni `vendor`, remplacent tout son contenu, dans un commit sur `main` s'il y a un changement. Pour sortir une version, monter `version` (semver) dans `composer.json`, dans le même changement que le code : le job pose alors l'étiquette `X.Y.Z` sur ce dépôt si elle n'existe pas encore ; sans nouvelle version, `main` avance et `^1.0` reste sur la dernière étiquette. Composer ne lit que les étiquettes `X.Y.Z` ou `vX.Y.Z` et saute une étiquette qui diffère de la `version` du `composer.json` qu'elle désigne. Le job écrit dans ce dépôt par la clé de déploiement du secret `MAGENTO_MODULE_DEPLOY_KEY` ; sans elle, il ne publie rien et le signale. Tout ce qui est sous `integrations/magento2` devient public : ni nom de marchand, ni donnée client, ni hôte interne, ni secret dans ses fichiers.

**1.0.0.** Première version publiée. Le défaut de `returnsentry/features/excluded_payment_methods` passe de vide à `globale` : une boutique qui n'a jamais enregistré ce réglage laisse désormais hors contrôle le passage des commandes payées par Global-e et leurs avoirs, toujours synchronisés (sections 6.4 et 6.6). Pour les contrôler de nouveau, vider le champ « Moyens de paiement exclus du contrôle » dans l'administration.

Les règles du module pour les agents et les contributeurs sont dans `CLAUDE.md`.

---

# ReturnSentry for Magento 2

The `ReturnSentry_Connector` module (Composer package `returnsentry/module-connector`, MIT license) connects a Magento Open Source 2.4.7 or later, Mage-OS or Adobe Commerce shop to a ReturnSentry instance. It sends customers, orders, parcels, returns and credit memos, asks for a decision at checkout, on every return and before a credit memo, and loads the beacon on the storefront. Its decision points hook into Magento's services: they apply to Luma, Hyvä, GraphQL and REST alike.

1. [What the module does](#1-what-the-module-does)
2. [Requirements and installation](#2-requirements-and-installation)
3. [Configuration](#3-configuration)
4. [Headless storefronts (GraphQL, REST)](#4-headless-storefronts-graphql-rest)
5. [The merchant's legal duties](#5-the-merchants-legal-duties)
6. [Operations](#6-operations)
7. [Troubleshooting](#7-troubleshooting)
8. [Development](#8-development)

## 1. What the module does

| Decision point | Luma, Hyvä | GraphQL | REST | Admin | Behaviour |
|---|---|---|---|---|---|
| Sync | yes | yes | yes | yes | Every saved customer, order, tracking number, credit memo and return goes to ReturnSentry through the message queue. |
| Checkout assessment | yes | `placeOrder` | `POST /V1/carts/mine/payment-information`, `PUT /V1/carts/mine/order`, `guest-carts` variants | no | Decision `allow`, `review`, `restrict_payment` or `block`, applied in Enforce, kept with no effect in Observe. |
| Payment method filtering | yes | `available_payment_methods` | `GET /V1/carts/mine/payment-methods`, `POST /V1/carts/mine/shipping-information` | no | In Enforce, hides the configured risky methods on `restrict_payment`. |
| Return terms before the order | Luma: notice in the payment step. Hyvä Checkout: no | cart field `returnsentry_return_terms` | `GET .../returnsentry-return-terms` | order panel | In Enforce: paid terms are shown, then declared displayed when the order is placed. |
| Recall in the confirmation | Magento's order email | same | same | no | Email block to add to the confirmation template. |
| Return assessment | Adobe Commerce RMA, `ReturnReporterInterface` | `requestReturn`, `requestGuestReturn` (RMA) | `POST /V1/returns` (RMA) | RMA | Decision shown to the team, never applied by the module. |
| Refund authorization | no | no | `POST /V1/order/:id/refund`, `POST /V1/invoice/:id/refund`, `POST /V1/creditmemo/refund` | credit memo | In Enforce, a credit memo can be stopped; in Observe, never. |
| Override | no | no | no | credit memo form | An authorized administrator refunds despite a stop, with a reason. |
| Beacon | loaded by the module | loaded by the storefront | loaded by the storefront | no | ReturnSentry script on the storefront, with the visitor's consent. |
| Visitor ID | cookies `rs_vid` and `rs_consent` | `X-ReturnSentry-*` headers | `X-ReturnSentry-*` headers | no | Joined to the assessment and the order only with consent. |
| Warehouse inspections | PHP code, `InspectionReporterInterface` | no | no | no | Findings per item attached to the return, sent with retries; they make the refund authorization decide again. |
| Erasure | no | no | no | customer deletion | Erases the customer in ReturnSentry, retrying until confirmed, when deleted in Magento, or through `CustomerErasureInterface` for a GDPR flow that anonymizes. |

The module does not do the following:

- It never blocks the creation of a return or the withdrawal.
- It does not assess orders created in the admin, orders placed with an admin or integration token, or orders imported through `POST /V1/orders`: they are synced, with no decision and no payment method filtering.
- It shows no return terms outside Enforce, nor on Hyvä Checkout or a one-page checkout, which show them themselves (section 5).
- It does not add the terms to the confirmation email until the merchant adds the block's line to their template.
- It receives no ReturnSentry webhooks: a stopped credit memo goes through when the administrator submits it again.
- It does not send the evidence link of a refund decision to the customer: the administrator sees it in the stop message.
- It does not catch up by itself on events that happened while the shop was disconnected: the `returnsentry:backfill` command does (section 6.3).
- `ReturnReporterInterface`, `InspectionReporterInterface` and `CustomerErasureInterface` are PHP contracts, with no REST or GraphQL route.

## 2. Requirements and installation

### 2.1 Requirements

- Magento Open Source 2.4.7 or later, Mage-OS or Adobe Commerce; PHP 8.2 to 8.5.
- Magento cron, which runs the `returnsentry_sync_retry` and `returnsentry_apply_retention` jobs and, depending on `env.php`, the queue consumer.
- A ReturnSentry instance and, per website, two keys created in the ReturnSentry console (Intégrations › Clés API) by an administrator of the merchant: a secret key `rs_sk_…` for Magento and a publishable key `rs_pk_…` for the beacon. The console shows a key only when it is created.
- A website whose base currency differs from the others is a separate ReturnSentry merchant, with its own keys: amounts are sent in the base currency.
- On Magento Open Source and Mage-OS, a returns extension, or merchant code, that reports returns through `ReturnReporterInterface` (section 6.5).

### 2.2 Installation

Three steps: install the package, update Magento, connect ReturnSentry (section 2.3).

```bash
composer config repositories.returnsentry vcs https://github.com/username-workspace/returnsentry-magento2
composer require returnsentry/module-connector:^1.0
bin/magento setup:upgrade
export RETURNSENTRY_URL=https://returnsentry.example.com RETURNSENTRY_PUBLISHABLE_KEY=rs_pk_...
read -rs RETURNSENTRY_SECRET_KEY && export RETURNSENTRY_SECRET_KEY
bin/magento returnsentry:connect
unset RETURNSENTRY_SECRET_KEY RETURNSENTRY_SALT
```

The package comes from the public repository `username-workspace/returnsentry-magento2`, which holds this module only, and from its `X.Y.Z` tags, one per version (section 8): no key is needed. `setup:upgrade` enables the new module.

Without access to GitHub from the server, Composer takes a copy of the module, a clone of that repository for example, declared as a `path` repository. Keep this copy at the same path while the module is installed: `composer install`, `composer update` and `module:uninstall` read it again.

```bash
composer config repositories.returnsentry '{"type": "path", "url": "/path/to/returnsentry-magento2", "options": {"symlink": false}}'
composer require returnsentry/module-connector:@dev
bin/magento setup:upgrade
```

In production mode, compile and deploy the static files after `setup:upgrade`, before `returnsentry:connect`:

```bash
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```

The module creates the tables `returnsentry_return`, `returnsentry_inspection`, `returnsentry_refund_authorization` and `returnsentry_sync_retry`, the columns `returnsentry_visitor_id`, `returnsentry_action`, `returnsentry_observed_action`, `returnsentry_level`, `returnsentry_score`, `returnsentry_return_paid`, `returnsentry_return_label_cost` and `returnsentry_erased` on `sales_order`, and the column `returnsentry_action` on `sales_order_grid`.

### 2.3 Connect ReturnSentry

One command saves the address and the keys, tests the connection and enables the module only if the test passes:

```bash
export RETURNSENTRY_URL=https://returnsentry.example.com RETURNSENTRY_PUBLISHABLE_KEY=rs_pk_...
read -rs RETURNSENTRY_SECRET_KEY && export RETURNSENTRY_SECRET_KEY
bin/magento returnsentry:connect
unset RETURNSENTRY_SECRET_KEY RETURNSENTRY_SALT
```

`RETURNSENTRY_URL`, `RETURNSENTRY_SECRET_KEY`, `RETURNSENTRY_PUBLISHABLE_KEY` and `RETURNSENTRY_SALT` stand for `--url`, `--secret-key`, `--publishable-key` and `--salt`. Read from the environment, the keys stay out of the shell history: `read -rs` reads the secret key without echoing it, and `unset` removes the secrets from the session once the command has run. The options remain available, at the cost of the history:

```bash
bin/magento returnsentry:connect --url https://returnsentry.example.com --secret-key rs_sk_... --publishable-key rs_pk_...
```

The command:

1. checks the formats as the admin does: an `https://` address, a `rs_sk_…` secret key, a `rs_pk_…` publishable key, a pseudonymization secret of at least 32 characters; it also refuses an empty or `0` `--website` and, without `--force-salt`, a pseudonymization secret different from the one already saved, which would separate pseudonymized customers from their history (section 3.4);
2. saves the settings at default scope, or at the scope of the `--website <code>` website, through the admin's save model, which encrypts the secret key and the pseudonymization secret and cleans the configuration cache; on a first connection, the module stays off during the test, and a scope already connected stays on. `--salt` also turns pseudonymization on (section 3.4). A path locked in `app/etc/env.php` or `config.php` keeps its value, as in the admin, and the command names it;
3. applies the `--profile <file.json>` profile (section 3.6), checked whole before the first save;
4. tests the connection as the "Test the connection" button does;
5. if the test passes, enables the module at the same scope; otherwise, prints the reason and exits with code 1, the module staying as step 2 left it. A refused format, address or profile exits with code 2, without enabling the module;
6. prints the mode of checkout decisions and refund authorization, which it does not change (`observe` by default, section 3.5), and the next steps.

Running the command again, to change a key for example, replaces the connection settings and tests again without turning the module off: sends go on and stored refund stops still apply. If that test fails, for an outage as for a wrong key, the module stays on with the new settings and fails open until they are fixed: a refused key or URL is reported (section 6.6) and sends refused for the key are retried (section 6.2). The same settings are in the admin (**Stores > Configuration > ReturnSentry > ReturnSentry**, Connection group, "Test the connection" button for the displayed scope), and every path of section 3.1 remains open to `bin/magento config:set`.

Then check that the consumer runs:

```bash
bin/magento queue:consumers:list | grep returnsentry.sync
bin/magento queue:consumers:start returnsentry.sync
```

Magento cron starts the consumer by itself when `cron_consumers_runner` is enabled in `env.php`; otherwise, run the second command under a process supervisor.

### 2.4 Uninstall

```bash
bin/magento module:uninstall --remove-data ReturnSentry_Connector
bin/magento setup:upgrade
bin/magento cache:flush
```

Before uninstalling, wait until the pending erasures system message is gone: the `returnsentry_sync_retry` table leaves with the module, and the erasures it holds with it. Settings stay in `core_config_data` (`path LIKE 'returnsentry/%'`). Data already sent stays in ReturnSentry, where erasure is done per merchant or per customer.

## 3. Configuration

### 3.1 Reference

A store view is **connected** when `returnsentry/general/enabled` is `1`, the URL and secret key are set and, with pseudonymization on, its secret is at least 32 characters long. Without a connection, the store view sends nothing, assesses nothing, keeps no visitor ID on its orders, does not load the beacon and does not show the panels, except erasures, overrides and inspections reported through `InspectionReporterInterface`, which go out as soon as the URL and secret key are set.

| Path | Scope | Default | Effect |
|---|---|---|---|
| `returnsentry/general/enabled` | store view | `0` | Main switch. |
| `returnsentry/general/api_url` | website | empty | ReturnSentry URL, `https://`; `http://` is accepted only for `localhost`, `127.0.0.1` and `[::1]`. Only the origin is kept. The console lives at the same address. |
| `returnsentry/general/secret_key` | website | empty | `rs_sk_…` key, encrypted in the database, marked sensitive for Magento, never logged. |
| `returnsentry/general/publishable_key` | website | empty | The beacon's `rs_pk_…` key; an `rs_sk_` key is refused. |
| `returnsentry/general/checkout_timeout_ms` | website | `800` | Budget of the checkout assessment, and of the admin panels. Minimum 50. Past it, checkout goes on as with `allow`. |
| `returnsentry/general/timeout_ms` | website | `3000` | Budget of the other calls: sync, returns, refund authorizations. Minimum 100. |
| `returnsentry/features/checkout_decisions` | store view | `observe` | `off`, `observe` or `enforce`: checkout assessment, payment method filtering and return terms. |
| `returnsentry/features/return_assessment` | store view | `1` | Assesses every return. Returns are synced even at `0`. |
| `returnsentry/features/refund_authorization` | store view | `observe` | `off`, `observe` or `enforce`: refund authorization. |
| `returnsentry/features/beacon` | store view | `1` | Loads the beacon on the Magento storefront. |
| `returnsentry/features/excluded_payment_methods` | store view | `globale` | "Payment methods excluded from control": codes of the methods whose payment and refunds an external checkout handles (`globale` for Global-e). Placing an order paid with one of them is neither assessed nor stopped, and its credit memos ask no refund authorization; the payment method list, computed before any method is chosen, is still assessed like any cart's; orders, credit memos and returns are still synced, and returns assessed (sections 6.4 and 6.6). |
| `returnsentry/checkout/risky_payment_methods` | store view | empty | Codes of the methods to hide on `restrict_payment` (deferred, split, cash on delivery). Empty: no filtering. |
| `returnsentry/privacy/pseudonymize` | website | `0` | Sends fingerprints instead of clear data (section 3.4). |
| `returnsentry/privacy/salt` | website | empty | Pseudonymization secret, at least 32 characters, encrypted. |
| `returnsentry/mapping/return_reasons` | store view | Adobe Commerce default reasons | Shop return reasons to standard reasons (section 3.2). |
| `returnsentry/mapping/refund_code_field` | store view | empty | Name of the credit memo field that holds the refund reason code. |
| `returnsentry/mapping/refund_codes` | store view | empty | Credit memo reason codes to standard categories. |
| `returnsentry/mapping/refund_with_return` | store view | `return` | Category of a credit memo linked to a return. |
| `returnsentry/mapping/refund_adjustment_only` | store view | `commercial_gesture` | Category of a credit memo with no item. |
| `returnsentry/mapping/refund_not_shipped` | store view | `other` | Category of a credit memo on an order not shipped. |
| `returnsentry/mapping/refund_shipped` | store view | `other` | Category of a credit memo on a shipped order, without a return. |
| `returnsentry/beacon/loading` | store view | `consent` | `consent`: after consent, and on the sign-in and account creation pages; `always`: on every page (United Kingdom). |
| `returnsentry/beacon/consent_cookie` | store view | empty | Name of the consent manager's cookie. |
| `returnsentry/beacon/consent_cookie_value` | store view | empty | Cookie item that means consent (section 3.3). |
| `returnsentry/beacon/consent_event` | store view | `returnsentry:consent` | Consent event on `window`. |

### 3.2 Mapping

**Return reasons.** Each shop reason label or code, in each language, gets a standard reason: `size_fit`, `changed_mind`, `quality`, `damaged_defective`, `not_as_described`, `wrong_item`, `late_delivery`, `multiple_sizes`, `other` or `unknown`. A standard reason sent as is is accepted; a label missing from the table counts as `unknown`. For Adobe Commerce, the table receives the admin labels (store view 0); a free-text "Other" reason counts as `other`. The table never turns a return into a return to sender, whatever its reason.

**Credit memos.** Each credit memo gets a category, in this order:

1. if `returnsentry/mapping/refund_code_field` names a field and the credit memo carries it, the `returnsentry/mapping/refund_codes` table decides; a code missing from the table counts as `other`;
2. otherwise, if the credit memo is linked to a return, `returnsentry/mapping/refund_with_return`, except for a return to sender, which is always `return_to_sender`. A credit memo is linked to the oldest open return (neither rejected nor cancelled) that shares at least one product with it, a received return coming first; a credit memo with no item links to any open return;
3. otherwise, `returnsentry/mapping/refund_adjustment_only` for a credit memo with no item, `returnsentry/mapping/refund_not_shipped` for an order not shipped, `returnsentry/mapping/refund_shipped` for a shipped order.

On an order paid in a currency other than the base currency (a cross-border sale), `return_to_sender` becomes `return`, for the credit memo as for the return.

The neutral categories, `defective_product`, `merchant_error`, `commercial_gesture` and `other`, are never submitted to refund authorization. A parcel claim (`item_not_received`, `empty_package_claim`, `missing_items_claim`, `return_not_received_dispute`) is checked only when the merchant chooses it: through the code table, or as the category of one of the four situations. On Magento Open Source, a return refund is recognized as such when the returns extension calls `ReturnReporterInterface`, through the code table, or when `returnsentry/mapping/refund_shipped` is `return`; otherwise it counts as `other` and is not submitted. Observe mode shows what would be checked: check this mapping before moving to Enforce.

### 3.3 Beacon and consent (Magento storefront)

With `returnsentry/features/beacon` at `1` and the store view connected, the module adds `<ReturnSentry URL>/beacon.js` with the publishable key to every storefront page, and the ReturnSentry URL to Magento's Content Security Policy (CSP). It declares the page type (`home`, `listing`, `product`, `cart`, `checkout`, `account`, `other`), with the `shipping` and `payment` steps of the Luma checkout and the `confirmation` step on the success page, and reports checkout, sign-in (Luma `login-form` form) and add to cart (Luma jQuery event `ajax:addToCart`).

On Hyvä, the script loads and runs without RequireJS or jQuery; add to cart, the `shipping` and `payment` steps and sign-in through the Hyvä form are not reported. These signals feed the browsing behaviour study, an optional console setting, not the score. Checkout, which feeds the score, is reported on any `checkout_index_index` page, whatever the theme.

Loading follows `returnsentry/beacon/loading`:

- `consent`: the script loads after consent, and without it on the `customer_account_login` and `customer_account_create` pages, where it secures authentication. Consent also calls `ReturnSentry.consent(true)`.
- `always`: the script loads everywhere; `ReturnSentry.consent(true)` is called only with consent. What ReturnSentry observes without consent enters no return, refund or claim decision.

Consent arrives in two ways, which can be combined:

- **Cookie**: the `returnsentry/beacon/consent_cookie` cookie contains `returnsentry/beacon/consent_cookie_value` as a whole item between the separators `,` `;` `|` `&` or spaces, or as the value of a `name=value` item. A name alone, such as `C0004`, matches `C0004:1`, `C0004=1` or `C0004:true`, never `C0004:0`. Examples: cookie `axeptio_authorized_vendors` and value `returnsentry` for Axeptio; cookie `OptanonConsent` and value `C0004:1` for OneTrust. Didomi encodes its cookie: use the event. The cookie is read once per page: consent given or withdrawn on the page counts from the next page. A cookie that does not decode (invalid `%` sequence) is read as is.
- **Event** on `window`, named by `returnsentry/beacon/consent_event`: `detail: true` grants consent; any other value withdraws it.

When consent is observed, the script sets the session cookie `rs_consent=1`. It removes it on a withdrawal event, or on a page where the configured consent cookie is present and no longer grants consent; it then erases the visitor ID from the device (`rs_vid`, cookie and `localStorage`), without loading the beacon, except on a page that loads the beacon without consent (sign-in and account creation pages, and every page with `always`), then calls `ReturnSentry.consent(false)`.

Wirings, to put in the consent manager tag, with ReturnSentry declared as a vendor or a "fraud prevention" purpose:

```js
// Didomi
window.didomiOnReady = window.didomiOnReady || [];
window.didomiOnReady.push(function (Didomi) {
  var send = function () {
    window.dispatchEvent(new CustomEvent('returnsentry:consent', {
      detail: Didomi.getUserStatusForVendor('<ReturnSentry vendor id>') === true
    }));
  };
  send();
  Didomi.on('consent.changed', send);
});

// OneTrust: category C0004, or the one chosen, in the active groups
function OptanonWrapper() {
  window.dispatchEvent(new CustomEvent('returnsentry:consent', {
    detail: (',' + window.OnetrustActiveGroups + ',').indexOf(',C0004,') > -1
  }));
}

// Axeptio
window._axcb = window._axcb || [];
window._axcb.push(function (sdk) {
  sdk.on('cookies:complete', function (choices) {
    window.dispatchEvent(new CustomEvent('returnsentry:consent', { detail: !!choices.returnsentry }));
  });
});
```

The server reads the visitor ID (`rs_vid` cookie) at checkout and when the order is placed only with a sign of consent: `rs_consent=1`, the configured consent cookie, or the `X-ReturnSentry-Consent: 1` header (section 4); with `always`, unconditionally. An ID out of format (8 to 100 characters among `A-Z`, `a-z`, `0-9`, `_` and `-`) is ignored. ReturnSentry links a device to a customer only if it saw it with the visitor's consent.

### 3.4 Pseudonymization

With `returnsentry/privacy/pseudonymize` at `1`, the module sends the hashed email (`<token>@pseudo.invalid`), address and phone fingerprints, the address country only, and no IP address. The visitor ID and the user agent are sent as they are, and the beacon talks to ReturnSentry directly. The computations are those of `tools/magento-export`: with the same secret, exported history and live data designate the same customers. Pseudonymization turns on only with a secret of at least 32 characters; a secret shortened later disconnects the website's store views. The secret must not change once data has been sent. The console then shows tokens instead of emails.

### 3.5 Rollout: observe, then enforce

Checkout decisions and refund authorization start in `observe`: the module calls ReturnSentry, keeps the decision, which the order shows as "would have: …", and applies nothing, even on a timeout or an error. The module sends the mode with each checkout assessment and each refund authorization; the console shows, for each point, observed, enforced or partly enforced, from the calls of the last 24 hours.

1. Connect ReturnSentry (section 2.3), leaving both points in `observe`.
2. Backfill the history (section 6.3).
3. Set the mapping (section 3.2) and consent (section 3.3), then compare the observed decisions with orders and credit memos.
4. Move to enforce, store view by store view:

```bash
bin/magento config:set --scope=stores --scope-code=<store view code> returnsentry/features/checkout_decisions enforce
bin/magento config:set --scope=stores --scope-code=<store view code> returnsentry/features/refund_authorization enforce
bin/magento cache:flush
```

5. To hide payment methods on `restrict_payment`, name them:

```bash
bin/magento config:set returnsentry/checkout/risky_payment_methods cashondelivery
```

A decision kept in Observe counts as a refund's last decision once in Enforce (section 6.6).

### 3.6 Merchant profiles

A profile is a JSON file that holds a merchant's own settings, kept outside the module: mapping, payment methods, store views left off.

```json
{
  "default": {
    "checkout/risky_payment_methods": "cashondelivery",
    "mapping/refund_code_field": "reason_code",
    "mapping/refund_codes": { "c1": { "shop": "CTR001", "standard": "item_not_received" } }
  },
  "websites": { "<website code>": { "privacy/pseudonymize": "1" } },
  "stores": { "<store view code>": { "general/enabled": "0" } }
}
```

```bash
bin/magento returnsentry:profile <file.json>
```

- Keys are the paths of section 3.1 without `returnsentry/`. Values are strings, as in `config.xml` (`"1"`, `"observe"`, comma-separated codes); the `mapping/return_reasons` and `mapping/refund_codes` tables are objects of `{"shop": …, "standard": …}` rows.
- The whole file is checked before the first save, with the admin's rules: a known path shown at that scope, a value among the field's options (installed payment methods included), a known standard reason or category, a non-empty shop value. Each scope is then saved at once by the admin's save model, which adds its own checks: pseudonymization refused without a 32-character secret, for example. A path locked in `app/etc/env.php` or `config.php` keeps its value, and the command names it.
- A profile never holds the address, the keys or the pseudonymization secret, which go through `returnsentry:connect` (section 2.3), and can only turn the module off (`general/enabled` at `0`), never on.
- A setting missing from the profile keeps its value. Put in it only what differs from the defaults: an `observe` written in the profile would bring a point moved to Enforce back to Observe each time it is applied.

## 4. Headless storefronts (GraphQL, REST)

A Nuxt, Next or PWA storefront calling Magento from its server gets the same decisions as Luma: the module relies on the Magento services that GraphQL and REST call.

- **Checkout assessment**: `placeOrder` (GraphQL); `POST /V1/carts/mine/payment-information`, `PUT /V1/carts/mine/order` and their `guest-carts` variants (REST). A refusal comes back as an error of the call, with the neutral message of section 6.4, in the store view's language. Only the customer's channels are assessed: customer token, guest cart, session.
- **Payment methods**: the cart's `available_payment_methods` (GraphQL), `GET /V1/carts/mine/payment-methods` and the answer of `POST /V1/carts/mine/shipping-information` (REST).
- **Adobe Commerce returns**: `requestReturn`, `requestGuestReturn` and `addReturnTracking` (GraphQL), `POST /V1/returns`, `PUT /V1/returns/:id` and `POST /V1/returns/:id/tracking-numbers` (REST) are synced and assessed as in the admin.
- **Refund authorization and sync**: the same whatever the channel.

The storefront has three things to do: load the beacon, pass the visitor ID and show the return terms.

### 4.1 Beacon

The storefront loads the script with its website's publishable key (`returnsentry/general/publishable_key`):

```html
<script src="https://<ReturnSentry URL>/beacon.js" data-key="rs_pk_..." async></script>
```

It follows `packages/beacon/README.md` of the ReturnSentry repository: in the European Union, load the script after consent, except on the sign-in and account creation screens, which call `ReturnSentry.exempt()`; call `ReturnSentry.consent(true)` or `ReturnSentry.consent(false)` from the consent manager; declare each page with `ReturnSentry.page(...)` after every route change; report `add_to_cart`, `login` and `checkout` with `ReturnSentry.track(...)`. The storefront's CSP allows the ReturnSentry URL in `script-src` and `connect-src`. For a storefront calling Magento from its server, only `returnsentry/beacon/loading` acts among the settings of section 3.3: at `always`, the ID is read without consent.

### 4.2 Visitor ID

The visitor's cookies do not reach Magento when the storefront calls it from its server. The storefront sends two headers with the calls made for this visitor, at least those listing payment methods and the one placing the order:

| Header | Value |
|---|---|
| `X-ReturnSentry-Visitor-Id` | The visitor ID: the `rs_vid` cookie the beacon sets on the storefront's domain, or `ReturnSentry.visitorId()` in the browser. |
| `X-ReturnSentry-Consent` | `1`, only when the visitor consented to the "fraud prevention" purpose. |

The header wins over the `rs_vid` cookie. Without `X-ReturnSentry-Consent: 1`, the ID is neither read nor sent, except with `returnsentry/beacon/loading` at `always`; an ID out of format is ignored. A storefront calling Magento from the browser, on the same site, can rely on the cookies of section 3.3. If the storefront sends the headers only when placing the order, the earlier assessments go without an ID and placing the order calls ReturnSentry a second time.

GraphQL, from the storefront's server:

```js
const returnSentry = consented && visitorId
  ? { 'X-ReturnSentry-Visitor-Id': visitorId, 'X-ReturnSentry-Consent': '1' }
  : {};

await fetch('https://magento.example.com/graphql', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Store: 'en_gb', ...returnSentry },
  body: JSON.stringify({
    query: 'mutation ($cart: String!) { placeOrder(input: { cart_id: $cart }) { orderV2 { number } } }',
    variables: { cart: cartId },
  }),
});
```

REST:

```bash
curl -X POST 'https://magento.example.com/rest/en_gb/V1/guest-carts/<cartId>/payment-information' \
  -H 'Content-Type: application/json' \
  -H 'X-ReturnSentry-Visitor-Id: k3Jd9QxW2bL0pZ7aR4tY1g' \
  -H 'X-ReturnSentry-Consent: 1' \
  -d '{"email": "customer@example.com", "paymentMethod": {"method": "checkmo"}}'
```

### 4.3 Return terms

With `returnsentry/features/checkout_decisions` at `enforce`, the storefront reads the cart's return terms once the customer's email and address are saved (`setGuestEmailOnCart` in GraphQL; in REST, a guest's email arrives through `POST /V1/guest-carts/:cartId/set-payment-information`), and again when the cart changes. Outside Enforce, the terms are `paid: false`.

```graphql
query ($cart: String!) {
  cart(cart_id: $cart) {
    returnsentry_return_terms { paid label_cost }
  }
}
```

```bash
curl 'https://magento.example.com/rest/en_gb/V1/guest-carts/<cartId>/returnsentry-return-terms'
```

Answer: `{"paid":true,"label_cost":6.5}`. For a signed-in customer: `GET /V1/carts/mine/returnsentry-return-terms`, with their token. `label_cost` is in the website's base currency; without a label cost, it is `null` in GraphQL and missing in REST.

When `paid` is `true`, the storefront shows, before the place order button, that returning the items is at the customer's cost and, with `label_cost`, that this cost is deducted from the refund. It then sends `X-ReturnSentry-Return-Terms-Displayed: 1` with the call placing the order, only if it showed them. The module declares the terms displayed only if this cart received paid terms through that field or route; without the header, returns of the order stay free. A storefront sending its own order confirmation repeats the displayed terms in it (section 5).

### 4.4 IP address

The order and the checkout assessment carry the IP address Magento sees. Behind a storefront, that is its server's, unless the storefront forwards the visitor's address (`X-Forwarded-For`) and Magento reads it, through the `alternativeHeaders` of `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress`.

## 5. The merchant's legal duties

The module applies decisions; their legal frame remains the merchant's. Full reference: `docs/legal.md` in the ReturnSentry repository. This is not legal advice.

- **Return terms announced before the order.** A `paid_return` or `statutory_only` charges for the return only if the customer's return terms were shown before the order was confirmed (French Consumer Code L221-5 8°, L221-23). In Enforce, the module shows them on Luma above the payment methods when they are paid, sets the session cookie `rs_return_terms=1`, and tells ReturnSentry, only when the order is placed, that they were displayed (`returnTermsDisplayed`). Another checkout shows them itself, then declares it with the `rs_return_terms=1` cookie (Magento storefront) or the `X-ReturnSentry-Return-Terms-Displayed: 1` header (headless storefront), and only if it really showed them. Without that declaration, returns of the order stay free: ReturnSentry answers `allow` with the reason `return_terms_not_announced`. A guest is known by email only when the order is placed: on Luma, a guest the cart gives no way to recognize (account, phone, visitor ID) sees no paid terms, and their returns stay free. A "free returns" banner carries its reservation. Only the direct cost of the label can be charged.
- **Recall in the confirmation.** The order confirmation repeats these terms (L221-13). The module keeps the announced paid terms on the order (`returnsentry_return_paid`, `returnsentry_return_label_cost`) and provides the email block `returnsentry_email_return_terms`, which renders nothing when returns are free. As soon as paid returns are decided, add this line, next to the item list, to the order confirmation templates for customers and for guests (Marketing > Communications > Email Templates; templates chosen in Stores > Configuration > Sales > Sales Emails > Order):

  ```
  {{layout handle="returnsentry_email_return_terms" order_id=$order_id area="frontend"}}
  ```

  A confirmation sent outside Magento (email marketing tool, headless storefront) repeats the displayed terms itself.
- **Consent.** In France and the European Union, reading the device outside sign-in and account creation requires consent (French Data Protection Act, article 82): keep `returnsentry/beacon/loading` at `consent` and wire the consent manager, through the cookie or the event (section 3.3). On a headless storefront, send `X-ReturnSentry-Consent: 1` only with that consent. The privacy policy lists these trackers.
- **Withdrawal.** No decision removes the 14-day withdrawal right or gates the "withdraw from the contract" button; the module blocks no return.
- **Refunds.** Refusing or reducing a refund stays a human decision, taken in the console. A hold without fraud evidence ends at the statutory deadline for a return, and after the carrier waiting period for a parcel claim, counted from `claimedAt`. The stop message gives the administrator, when ReturnSentry provides one, the evidence link to pass on to the customer.
- **Payment.** Hiding a payment method relies on behaviour criteria, always keeps at least one method and is disclosed to the customer before the order. Refusing an order (`block`) only comes from an operator decision.
- **Personal data.** Data processing agreement with the ReturnSentry operator, impact assessment, customer information (clause 7.12 of `docs/legal.md`). The data the module keeps in Magento is described in `docs/legal.md`, section 2.14. Deleting a customer account in Magento erases it in ReturnSentry; for a customer without an account, erase through `DELETE /v1/customers?email=…` (the pseudonymous token when pseudonymization is on) or `rs customer:erase`.

## 6. Operations

### 6.1 Queue and cron

| Item | Name | Role |
|---|---|---|
| Topic and consumer | `returnsentry.sync` | Sends each record the observers publish. MySQL queue by default, AMQP when `env.php` configures it. |
| Cron job | `returnsentry_sync_retry`, group `returnsentry`, every minute | Replays failed sends, sends erasures, overrides and inspections, 200 rows per run. |
| Cron job | `returnsentry_apply_retention`, group `returnsentry`, daily at 03:17 | Applies the retention periods (section 6.7). |
| Table | `returnsentry_sync_retry` | Sends waiting for another try. |
| Table | `returnsentry_inspection` | Reported inspections, until ReturnSentry receives them or refuses them for their content, or until the night after their send is abandoned. |

```bash
bin/magento queue:consumers:start returnsentry.sync
bin/magento cron:run --group=returnsentry
```

### 6.2 Sync

The observer publishes a message; the consumer reloads the entity and sends it. Sending again updates the row in ReturnSentry.

| Magento event | Sent to | Identifier (`externalId`) |
|---|---|---|
| `customer_save_after_data_object` (save through the customer repository) | `POST /v1/customers` | `m2:<customer id>` |
| `sales_order_save_commit_after` | `POST /v1/orders` | order increment ID |
| `sales_order_shipment_track_save_commit_after` | `POST /v1/shipments`, one per tracking number | carrier and tracking number |
| `sales_order_creditmemo_save_commit_after` | `POST /v1/refunds` | `cm:<credit memo increment ID>` |
| `rma_save_commit_after`, and `core_abstract_save_commit_after` for an RMA tracking number | `POST /v1/returns` | `rma:<RMA increment ID>` |
| `ReturnReporterInterface::report()` | `POST /v1/returns` | the caller's |
| `customer_delete_commit_after` | `DELETE /v1/customers?externalId=m2:<customer id>` | `m2:<customer id>` |

- **Order**: items with their catalog unit weight, amounts in base currency (`paidAmount` and `currency` in the order currency), shipping country and address or their fingerprints, payment method, a card descriptor when the payment provider stores the last four digits, the visitor ID captured when the order is placed. An order in `pending_payment` or `payment_review` is sent once the payment is confirmed.
- **Parcel**: each tracking number becomes an outbound parcel. The carrier is brought to its standard code (`colissimo`, `chronopost`, `dpd_fr`, `ups`…) from its Magento code, then its title, `other` otherwise. A shipment with a single tracking number adds its items and total weight; with several numbers, neither. Weights are converted to grams from `general/locale/weight_unit`; a zero, missing, unknown-unit or over-1,000 kg weight is not sent.
- **Return**: it carries its tracking numbers (20 at most), with the carrier brought to its code the same way.
- **Identifiers**: those of `tools/magento-export`, so that imported history and live data point to the same customers and orders. The export always pseudonymizes: a guest customer matches only when the module is pseudonymized with the same secret.
- **Errors**: a transient error (network, timeout, HTTP 429 or 5xx, unreadable answer) or a refused secret key (401, 403: revoked, wrong or being replaced) schedules another try after 1, 2, 4, 8… minutes, up to 6 hours apart, 20 times at most, about three days; past that, the send is dropped and logged. A permanent error (any other HTTP 4xx refusal: data refused, a wrong URL, 404) is logged and not retried: the send is dropped, not queued. Once the URL is fixed, or a key that stayed refused for more than three days, catch the period up with the backfill (`bin/magento returnsentry:backfill --from=<date of the first refusal>`). An erasure is retried until ReturnSentry confirms it, an override until it accepts or refuses it.
- **Disconnected shop**: an event that happens while a store view is not connected is not queued. Catch it up with the backfill.

### 6.3 History backfill

```bash
bin/magento returnsentry:backfill --from=2024-01-01 --to=2026-09-01 --dry-run
bin/magento returnsentry:backfill --from=2024-01-01 --to=2026-09-01
bin/magento returnsentry:backfill --from=2024-01-01 --store=fr_fr
```

- `--from` is required and included, `--to` excluded (tomorrow by default); dates `YYYY-MM-DD` in UTC. `--store` takes a store view code; by default, every connected view. `--dry-run` counts without sending, including on a view that is not connected.
- The command sends, in batches of 500 to `POST /v1/batch`, the customers of the period's orders, the orders, their parcels, their returns and their credit memos. It skips orders waiting for payment and those of an erased customer. It assesses no return and asks for no refund authorization.
- Each refused record is printed with its batch, position, type, Magento reference and reason. A failed batch (network, 5xx, 120 s timeout) stops that store view, with a failure exit code; the other views go on. Since sends are idempotent, run the same period again.
- Safety net against lost sends, in the server's crontab (GNU `date`):

```bash
30 3 * * * cd /var/www/magento && bin/magento returnsentry:backfill --from=$(date -u -d '3 days ago' +\%F)
```

### 6.4 Checkout assessment

**When.** When the order is placed, the `returnsentry_apply_checkout_decision` observer of `sales_model_service_quote_submit_before` assesses the cart (`POST /v1/assessments`, context `checkout`). In Enforce, the cart is also assessed when Magento lists payment methods, if at least one risky method is configured, and when the return terms are read (section 4.3, Luma notice). Not assessed: carts from the admin or from an admin or integration token, placing an order paid with a method of `returnsentry/features/excluded_payment_methods`, a cart to ship with no shipping country, a cart with neither a customer reference nor a visitor ID.

**External checkout.** An external checkout such as Global-e creates its orders server to server through an anonymous REST route (`POST /V1/international/order/create`, payment method `globale`) and its refunds through `POST /V1/international/order/refund`. Magento gives every anonymous REST call the guest user type (`UserContextInterface::USER_TYPE_GUEST`), the one of a shopper paying on a headless storefront: the channel does not tell them apart. Exclude the external checkout's payment method: placing its orders is neither assessed nor stopped, and its credit memos go through without authorization, which leaves its refund flow intact. Only the payment method list is still assessed and filtered like any cart's: Magento computes it as soon as the shipping method is chosen (`shipping-information` in REST, `setShippingMethodsOnCart` in GraphQL), before any payment method, when nothing tells yet that the order will be paid with an excluded method. The module excludes `globale` by default; for another external checkout, add its code:

```bash
bin/magento config:set returnsentry/features/excluded_payment_methods globale,<method code>
```

**Content.** Context `checkout`, mode, `returnTermsDisplayed` (`true` only when the order is placed, section 5), customer, device (visitor ID with consent, IP address unless pseudonymized, user agent), order (reserved number, amount and items in base currency, shipping address). The order number is reserved at the cart's first assessment: an abandoned cart leaves an unused number.

**Kept answer.** 10 minutes for this cart and content, one minute after a failure. Placing the order reuses the answer when nothing changed; it calls ReturnSentry again when a guest's email only arrives at that moment, or when paid return terms were displayed.

**Timeout or error.** Past `returnsentry/general/checkout_timeout_ms`, or on an error, the order goes through as with `allow` and the incident is logged; an operator decision returned with `failOpen: true` still applies.

| Action | Enforce | Observe |
|---|---|---|
| `allow` | Nothing. | Nothing. |
| `review` | The order goes through, with the comment "ReturnSentry recommends a manual review of this order (level …, score …). Reasons: …" and "Review" in the ReturnSentry column of the grid. | Kept on the order, "would have: Review". |
| `restrict_payment` | The methods of `returnsentry/checkout/risky_payment_methods` are hidden, unless they are the only ones available. A hidden method sent directly is refused: "This payment method is not available for this order. Please choose another payment method." | Kept, with no effect. |
| `block` | Order refused: "We cannot complete this order. Please contact our customer service." ReturnSentry produces `block` only from an operator decision. | Kept, with no effect. |

The order keeps `returnsentry_level` and `returnsentry_score`, and `returnsentry_action` in Enforce or `returnsentry_observed_action` in Observe.

### 6.5 Return assessment

Each new return is sent, then assessed by the consumer (`POST /v1/assessments`, context `return`), and assessed again when its receipt date (`goodsBackAt`) arrives. The decision is stored on the return and shown in the order panel; the module never applies it. An assessment without an answer is not stored; it is made again on the next send of the return.

| Action | Meaning shown |
|---|---|
| `allow` | Return on your usual conditions. |
| `paid_return` | The customer pays the direct cost of the label, if announced before the order. |
| `inspect_before_refund` | Refund once the parcel has been inspected, within the legal deadline. |
| `statutory_only` | Legal withdrawal only, always accepted: 14 days, return at own cost, inspection before refund. On an order without announced paid terms: "…14 days, free return, inspection before refund." |

`paid_return` and `statutory_only` only come from an operator decision; the return is at the customer's cost only if paid terms were announced for the order (section 5), which the meaning shown reads (`returnsentry_return_paid`).

**Adobe Commerce.** An RMA is sent and assessed right after its creation, then again on the first received status of its history. Statuses: `pending`, `unconfirmed`, `authorized`, `partially_authorized` → `requested`; `received`, `received_on_item` → `received`; `approved`, `approved_on_item` → `received`; `processed_closed` → `refunded`; `rejected`, `rejected_on_item`, `denied` → `rejected`; `closed` → `cancelled`. An RMA is always a customer return.

**Magento Open Source and Mage-OS.** A returns extension, or merchant code, reports each return through `ReturnSentry\Connector\Api\ReturnReporterInterface`, at creation and at every status change:

```php
use ReturnSentry\Connector\Api\Data\ReturnItem;
use ReturnSentry\Connector\Api\Data\ReturnRequest;
use ReturnSentry\Connector\Api\Data\ReturnShipment;
use ReturnSentry\Connector\Api\ReturnReporterInterface;

public function __construct(private readonly ReturnReporterInterface $returnSentry)
{
}

$assessment = $this->returnSentry->report(new ReturnRequest(
    externalId: 'RET-000123',
    orderId: (int) $order->getEntityId(),
    requestedAt: $returnRequestedAt,
    items: [new ReturnItem(orderItemId: (int) $orderItem->getItemId(), quantity: 1, reason: 'Too small')],
    status: 'received',
    receivedAt: $parcelReceivedAt,
    shipments: [new ReturnShipment(trackingNumber: '8R00123456789', carrier: 'colissimo')],
));
```

| Parameter | Meaning |
|---|---|
| `externalId` | Return identifier, 200 characters at most, unique among all returns. |
| `orderId` | The order's `entity_id`. |
| `requestedAt` | Return request date, that is the withdrawal declaration. Always the same from one report to the next: it starts the refund deadline. |
| `items` | Returned order lines, quantity, and `reason`: a shop label (reason table) or a standard reason. |
| `status` | `requested`, `in_transit`, `received`, `refunded`, `rejected` or `cancelled`. |
| `reason` | Reason of the whole return. `return_to_sender` declares a parcel the carrier brought back because the customer never collected it; it is ignored on an order paid in a currency other than the base currency. |
| `receivedAt` | Parcel receipt date. A new `receivedAt` triggers a new assessment; this date bounds a refund hold. |
| `shipments` | Return parcel: tracking number, carrier code or title. |

`report()` stores the return, queues it and returns the decision already stored (`action`, `level`, `score`, `reasons`), or `assessed: false` with the action `allow` until it exists. It throws `LocalizedException` for an empty identifier or an unknown status, and `NoSuchEntityException` for an unknown order.

**Warehouse inspections.** The merchant's code that reads its warehouse's file or calls reports each inspection through `ReturnSentry\Connector\Api\InspectionReporterInterface`. The inspection is attached to the return the module recorded: `rma:<RMA number>` for an Adobe Commerce RMA, known to the module after its first send, or the `externalId` given to `ReturnReporterInterface`. It is kept in `returnsentry_inspection` and sent by the cron job to `POST /v1/inspections`. A transient error, or a refused key or URL (401, 403, 404), is retried, 20 times at most, over about three days; the inspection is deleted from Magento once ReturnSentry received it, or refused it for its content. An inspection whose send is abandoned, after the twentieth attempt or for want of a URL and a secret key on the store view, is deleted by the retention job the following night. A new inspection makes the refund authorization decide again at the next request: a finding of the fraud family holds the refund for an operator.

```php
use ReturnSentry\Connector\Api\Data\InspectionItem;
use ReturnSentry\Connector\Api\Data\InspectionRequest;
use ReturnSentry\Connector\Api\InspectionReporterInterface;

public function __construct(private readonly InspectionReporterInterface $inspections)
{
}

$this->inspections->report(new InspectionRequest(
    externalId: 'WH-20260920-14',
    returnExternalId: 'rma:' . $rma->getIncrementId(),
    inspectedAt: $inspectedAt,
    items: [new InspectionItem(sku: 'DRESS-M', quantity: 1, outcome: 'wrong_item')],
    note: 'Line 14 of the file',
    photoUrls: ['https://warehouse.example.com/photos/wh-20260920-14.jpg'],
));
```

| Parameter | Meaning |
|---|---|
| `externalId` | Inspection ID, 200 characters at most. Reported again with the same ID, the inspection replaces the previous one. |
| `returnExternalId` | Inspected return, as the module recorded it. |
| `inspectedAt` | Date of the inspection. |
| `items` | 1 to 500 items: SKU of 200 characters at most, quantity from 1 to 10,000, standard finding from `InspectionItem::OUTCOMES` (`docs/inspections-and-refunds.md` in the ReturnSentry repository). |
| `note` | Internal note, 2,000 characters at most, never shown to the customer. |
| `photoUrls` | `http` or `https` URLs of the photos, 30 at most, each 2,000 characters at most. |

`report()` throws `LocalizedException` for a request ReturnSentry would refuse, and `NoSuchEntityException` for a return the module has not recorded. For an erased customer, it keeps and sends nothing.

**Warehouse codes.** The merchant chooses the standard finding for each code of its warehouse. Example for a file that codes each returned item from 1 to 4, to confirm with the warehouse:

| Code | Meaning in the file | Finding | To confirm with the warehouse |
|---|---|---|---|
| 1 | Item as sold | `conforming` | |
| 2 | Wrong item | `wrong_item` | `substituted_object` if the code means an unrelated object (ballast, another product) rather than another item. Both belong to the fraud family. |
| 3 | Broken item | `damaged_by_customer` | `transport_damage` if the damage comes from transport, `manufacturing_defect` for a manufacturing defect: these two findings are neutral and leave the refund approved. |
| 4 | Locked item | none | Meaning of the code. Until then, do not send the item; an inspection without items is refused. |

### 6.6 Refund authorization

**When.** The `returnsentry_authorize_refund` plugin before `Magento\Sales\Model\Order\RefundAdapterInterface::refund` asks ReturnSentry (`POST /v1/refund-authorizations`) before any money leaves, for every credit memo created in the admin or through `POST /V1/order/:id/refund`, `POST /V1/invoice/:id/refund` or `POST /V1/creditmemo/refund`. Not submitted: credit memos in the neutral categories (section 3.2), those of an order paid with a method excluded from control (section 6.4), those of an erased customer, and refunds started from the payment provider and only recorded in Magento.

**Request.** Identifier `ra:<order>:<line>x<quantity>…` (`adjustment` for a credit memo with no item; hashed past 120 characters; `:2`, `:3` for a repeated identical credit memo), linked return (section 3.2), requested amount (credit memo total plus the adjustment fee), return request date (`withdrawnAt`), receipt date (`goodsBackAt`: first received status of the RMA, or `receivedAt` from `ReturnReporterInterface`), mode. A stopped credit memo submitted again finds its decision. Decisions are stored even when the credit memo is stopped.

**Parcel claims.** A credit memo classified as a claim carries its category and `claimedAt`, the date of the first request that got a decision. A claim on the outbound parcel carries the tracking number when the credit memo's items travelled in a single parcel, and for missing items, the credit memo's items; it cites no return. `return_not_received_dispute` cites its return. Without a linked open return, the credit memo goes as a return refund, with the customer: ReturnSentry applies its return rules (operator decisions, a hold until the statutory deadline for an uninspected return), since the claim category requires a return. A claim on the outbound parcel is decided on carrier facts.

| Decision | Enforce |
|---|---|
| `approve` | The credit memo is created. |
| `approve_partial` | The credit memo is stopped with the approved amount and the adjustment fee to enter; submitted again with that adjustment, it goes through. |
| `hold`, `deny` | The credit memo is stopped with the reasons, the parcel state for a claim, and, when ReturnSentry provides them, the date from which a new credit memo will be approved and the evidence link. The message links to the decision in the console (`/refunds?refund=<id>`), where an operator can change it. |

Through `POST /V1/order/:id/refund` and `POST /V1/invoice/:id/refund`, the stop message comes back as an error of the call.

**Refund started by code.** A stop throws `ReturnSentry\Connector\Exception\RefundStoppedException`, which extends `Magento\Sales\Exception\CouldNotRefundException`. Code calling `RefundAdapterInterface::refund()`, `RefundOrderInterface::execute()` or `RefundInvoiceInterface::execute()` receives that exception itself; `CreditmemoManagementInterface::refund()`, the one behind the admin form and `POST /V1/creditmemo/refund`, replaces it with a `LocalizedException` carrying the same message.

| Method | Value |
|---|---|
| `getMessage()` | The stop message shown in the admin. |
| `getDecision()` | `hold`, `deny` or `approve_partial`. |
| `getReasons()` | Labels of the decision's reasons; empty when ReturnSentry did not answer and the stored decision applies. |
| `getHoldUntil()` | ISO 8601 UTC date from which, without new evidence, a new credit memo will be approved; `null` without a deadline. |
| `getEvidenceUrl()` | Evidence link to share with the customer, or `null`. |

An automated flow, such as the import of a warehouse file that refunds returns, catches it before any other exception, moves the item to manual review and does not retry the credit memo: submitted again, it gets the same decision until an operator changes it in the console, or, for a hold, until `getHoldUntil()`. A `catch (\Exception $e)` that throws its own exception without the previous one loses the type: catch `RefundStoppedException` before it.

```php
use ReturnSentry\Connector\Exception\RefundStoppedException;

try {
    $order = $this->refundAdapter->refund($creditmemo, $order, $isOnline);
} catch (RefundStoppedException $stop) {
    $this->manualReview->add($returnLine, $stop->getDecision(), $stop->getReasons(), $stop->getHoldUntil());
    return;
}
```

**No answer.** Timeout, network error, HTTP error or degraded answer: the credit memo goes through, with the comment "ReturnSentry did not answer: this refund went through unchecked.", a log line and a mention in the panel; it reaches ReturnSentry through the credit memo sync. It stays stopped when the last decision stored for this refund (for a claim without one, the order's last) is a `hold` that ReturnSentry marks as based on fraud evidence (`fraudEvidence`) or a `deny`, and when this refund's last decision is an `approve_partial` whose credit memo exceeds the approved amount. A decision kept in Observe counts as the last decision.

**Refused key or URL.** A 401, 403 or 404 answer also lets the credit memo through, so that a configuration error blocks no refund that is due, and reports it: the comment "ReturnSentry refused the secret key or the URL: this refund went through unchecked.", the mention "Refunded unchecked, ReturnSentry refused the secret key or the URL" in the panel, and the system message "ReturnSentry refuses the secret key or the URL of <website>: refund control is inactive." The message goes away at ReturnSentry's next answer to a refund authorization of that website, or after a successful connection test.

**Observe.** The decision is stored and shown ("would have: …"); the credit memo is always created. The log records only the decisions that would have stopped it.

**Override.** The "Refund despite ReturnSentry" permission (ACL `ReturnSentry_Connector::refund_override`, Sales > Operations > Orders > Actions > Credit Memos) shows, on the admin credit memo form and in Enforce, a checkbox and a reason of at least 3 characters. The checkbox lets a stopped credit memo through; the override is stored in the module and in the order history ("Refunded despite ReturnSentry, by <administrator>: <reason>"). The cron job sends it to `POST /v1/refund-authorizations/{externalId}/decision` (decision `approve`, reason, administrator's username), retrying on transient errors and on a refused secret key (401, 403), at most 6 hours apart, until ReturnSentry accepts or refuses it. ReturnSentry accepts the override of a hold the shop already refunded. A refused secret key (401, 403) shows on the panel at once, "Not sent to ReturnSentry yet: it refused the secret key (HTTP …). Sent again once the key is fixed.", and the line goes once the send goes through. Any other refusal is final: for a 409, the last decision is no longer on hold or denied (already approved, in full or in part), and the panel shows "Not sent to ReturnSentry: decision already final."; for another error, "Not sent to ReturnSentry: it refused the override (HTTP …). Record it in the console." A credit memo created through REST is unblocked in the console.

### 6.7 Erasure and retention

Deleting a customer in Magento (`customer_delete_commit_after`) keeps an erasure request in `returnsentry_sync_retry`, which the cron job sends to `DELETE /v1/customers?externalId=m2:<id>` with the keys of the customer's website, until confirmed, even with the module disabled. Without a URL or a secret key on that website, nothing is kept: erase the customer in ReturnSentry with `DELETE /v1/customers` or `rs customer:erase`. While erasures are pending, an admin system message counts them and quotes the latest error. Magento keeps the customer's orders without the account link: the module does not send them, nor their returns and credit memos, and asks no refund authorization for them.

**GDPR flow that anonymizes.** A flow that anonymizes the customer with SQL queries, without deleting them, does not fire `customer_delete_commit_after`. `ReturnSentry\Connector\Api\CustomerErasureInterface::eraseByEmail()` is the entry point for any merchant GDPR flow: it calls it **before** anonymizing, with the original email and the website whose ReturnSentry keys receive the erasure. A flow that only knows the email calls it for each website.

```php
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Api\CustomerErasureInterface;

public function __construct(
    private readonly CustomerErasureInterface $returnSentry,
    private readonly StoreManagerInterface $storeManager
) {
}

foreach ($this->storeManager->getWebsites() as $website) {
    $this->returnSentry->eraseByEmail($originalEmail, (int) $website->getId());
}
```

The call:

- keeps an erasure request in `returnsentry_sync_retry`, which the cron job sends to `DELETE /v1/customers?email=…` with the website's keys, erasing in ReturnSentry every account at that email, guests included, with the same retries as a deletion. With pseudonymization, the email's token is sent. The email stays in the request until confirmed;
- also keeps, for each Magento account at that email, the erasure request by its ID (`m2:<id>`) that its deletion would send: if the anonymization changes the account's email through the customer repository before the request by email leaves, that change reaches ReturnSentry and the request by email no longer finds the account, which is erased all the same;
- on the orders of that website's store views placed with that email or by an account at that email, erases the visitor ID, marks the order (`returnsentry_erased`) so that the module no longer sends it, nor its returns, inspections and credit memos, and no longer asks a refund authorization for it, and deletes its rows from `returnsentry_return`, `returnsentry_refund_authorization` and `returnsentry_inspection`. The level, score, checkout action and announced terms stay on the order with its other fields, which the merchant's anonymization handles.

It throws `LocalizedException` for a value that is not an email address, and `NoSuchEntityException` for an unknown website. Without a URL or a secret key on that website, it erases the module's data and keeps no request.

**Retention in Magento.** Every night, the `returnsentry_apply_retention` job erases the visitor ID (`returnsentry_visitor_id`) from orders created more than 13 months ago, the tracker retention ReturnSentry applies too (`Retention::TRACKER_RETENTION_MONTHS`), and deletes refund authorizations whose order no longer exists and inspections with no send pending any more. Stored returns (`returnsentry_return`) leave with their order, as do inspections not sent yet (`returnsentry_inspection`). The level, score, checkout action, announced return terms and review comment are part of the order and follow its retention.

### 6.8 Log

`var/log/returnsentry.log` receives failures and fallbacks: checkout assessment without an answer, refund authorization failed or degraded, credit memo let through unchecked, observed decision that would have stopped a credit memo, override, dropped send, message that could not be queued. Successful calls are not logged. The log holds no key and no payment data.

### 6.9 Admin

- **Order**, Information tab (`Magento_Sales::actions_view` permission): checkout decision ("would have: …" in Observe), announced paid return terms, the customer's current risk (level, score, reasons, link to their console profile), return decisions with their meaning, refund authorizations ("would have: …" in Observe), credit memos let through unchecked or by override. The current risk is read on display, within the `returnsentry/general/checkout_timeout_ms` budget.
- **Customer**: "ReturnSentry" tab, with the current risk and the console link.
- **Order grid**: "ReturnSentry" column, filterable, filled in Enforce ("Allow", "Review", "Restricted payment").
- **System messages**: pending erasures; secret key or URL refused by ReturnSentry, refund control inactive.
- The panels show only for a connected store view.

## 7. Troubleshooting

| Symptom | Cause | Check | Fix |
|---|---|---|---|
| Nothing reaches ReturnSentry. | Store view not connected, or consumer stopped. | `bin/magento config:show returnsentry/general/enabled`, "Test the connection" button, `bin/magento queue:consumers:list`. | Connect (section 2.3); run `bin/magento queue:consumers:start returnsentry.sync`. |
| Orders are missing after an outage. | Events during the disconnection, or retries exhausted after about three days. | `var/log/returnsentry.log` ("ReturnSentry sync failed"). | `bin/magento returnsentry:backfill --from=<date>`. |
| Credit memos go through with "ReturnSentry did not answer". | ReturnSentry unavailable, or slower than `returnsentry/general/timeout_ms`. | `var/log/returnsentry.log` ("refund authorization failed"). | Check ReturnSentry's availability; raise the budget. |
| System message "ReturnSentry refuses the secret key or the URL of …". | Revoked or wrong secret key, or wrong URL: credit memos go through unchecked. | "Test the connection" button. | Fix the key or the URL; the message goes away after a successful test. Data sends refused for the key are retried for about three days; past that, or for a wrong URL, `bin/magento returnsentry:backfill --from=<date of the first refusal>`. |
| A credit memo is stopped. | Decision `hold`, `deny` or `approve_partial` in Enforce, or, while ReturnSentry does not answer, a stored decision that stops it (section 6.6). | Stop message, order panel. | Change the decision in the console, enter the adjustment for `approve_partial`, or override (section 6.6). |
| The ReturnSentry panel does not show. | Store view not connected, or `Magento_Sales::actions_view` permission missing. | Configuration of the order's scope. | Connect the store view; grant the permission. |
| The beacon does not load. | `returnsentry/features/beacon` at `0`, publishable key empty or `rs_sk_`, view not connected, no consent in `consent` mode, a theme CSP that ignores Magento's. | Page source (`beacon.js`), browser console. | Set the publishable key, wire consent (section 3.3). |
| Orders have no visitor ID. | No sign of consent, no `rs_vid` cookie, headers missing on a headless storefront, ID out of format. | `returnsentry_visitor_id` column of `sales_order`. | Wire consent; send the headers of section 4.2. |
| A paid return is not applied (`return_terms_not_announced`). | Terms not shown or not declared: Observe, guest not recognized on Luma, header missing, checkout without the notice. | The order's `returnsentry_return_paid` column. | Section 4.3 or 5; the return of that order stays free. |
| The confirmation does not repeat the paid terms. | The block's line is missing from the email template. | Template chosen in Stores > Configuration > Sales > Sales Emails > Order. | Add the line of section 5. |
| A reported inspection does not reach ReturnSentry. | ReturnSentry unreachable, key or URL refused, or inspection refused for its content (log "ReturnSentry sync failed"). | `SELECT entity_id, attempts, last_error FROM returnsentry_sync_retry WHERE entity_type = 'inspection';` and `var/log/returnsentry.log`. | Fix the URL or the key within three days: the cron job sends it again. An inspection refused for its content is reported again, corrected. |
| The system message counts pending erasures. | ReturnSentry unreachable or the website's key refused. | `SELECT entity_id, attempts, last_error FROM returnsentry_sync_retry WHERE entity_type = 'erasure';` | Fix the URL or the key; the cron job sends them again. |
| Pseudonymization refuses to turn on. | Secret shorter than 32 characters. | Message "Enter a pseudonymization secret of at least 32 characters…". | Save the secret first, then turn it on. |
| The customer sees "We cannot complete this order". | Operator decision `block` in Enforce. | The customer's page in the console. | Review the operator decision in the console. |

## 8. Development

Development dependencies come from the public Mage-OS mirror (no Adobe keys) and Packagist. `require-dev` pins the Mage-OS version (`mage-os/framework`); moving it is a deliberate change, with no effect on shops.

```bash
cd integrations/magento2
composer install
composer check
```

`composer check` runs `composer lint` (php -l), `composer phpcs` (Magento 2 standard, `phpcs.xml.dist`), `composer phpstan` (level 6, with `--memory-limit=2G`: a direct call takes `vendor/bin/phpstan analyse --memory-limit=2G`, or it stops on a meaningless error) and `composer test` (PHPUnit, `unit` suite). The repository's CI runs `composer check` on every pipeline (`magento` job, Alpine's PHP 8.3).

Round trip with a real ReturnSentry API:

```bash
RS_E2E_URL=http://localhost:4000 RS_E2E_SECRET_KEY=rs_sk_... composer test:e2e
```

To check the module in a real shop (Luma, GraphQL, REST, admin, queue, cron, emails), follow [`tools/magento-e2e/README.md`](../../tools/magento-e2e/README.md), from the repository root:

```bash
tools/magento-e2e/e2e.sh up && tools/magento-e2e/e2e.sh api
tools/magento-e2e/e2e.sh module --worktree && tools/magento-e2e/e2e.sh seed
tools/magento-e2e/e2e.sh test all
```

**Release.** After each green pipeline on `main`, the CI's `magento-module` job publishes a snapshot of the module to the public repository `username-workspace/returnsentry-magento2`: the tracked files of `integrations/magento2`, without history or `vendor`, replace all of its content, in a commit on `main` when something changed. To release, raise `version` (semver) in `composer.json`, in the same change as the code: the job then puts the `X.Y.Z` tag on that repository when it does not exist yet; without a new version, `main` moves on and `^1.0` stays on the last tag. Composer reads only `X.Y.Z` or `vX.Y.Z` tags and skips a tag that differs from the `version` of the `composer.json` it points to. The job writes to that repository with the deploy key of the `MAGENTO_MODULE_DEPLOY_KEY` secret; without it, it publishes nothing and says so. Everything under `integrations/magento2` becomes public: no merchant name, customer data, internal host or secret in its files.

**1.0.0.** First published version. The default of `returnsentry/features/excluded_payment_methods` changes from empty to `globale`: a shop that never saved this setting now leaves out of control the placing of orders paid with Global-e and their credit memos, still synced (sections 6.4 and 6.6). To control them again, clear the "Payment methods excluded from control" field in the admin.

The module's rules for agents and contributors are in `CLAUDE.md`.
