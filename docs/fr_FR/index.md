# LocalHomeConnect

LocalHomeConnect permet à Jeedom de lire et de piloter directement sur le réseau local les appareils compatibles Home Connect. Après la préparation initiale, les échanges d’usage ne passent plus par le cloud Home Connect.

## Avant de commencer

Le plugin nécessite Jeedom 4.4 ou plus récent et Debian 12. Node.js 20 ou plus récent est installé ou vérifié par le mécanisme `NebzHB/dependance.lib` lors de l’installation des dépendances.

L’appareil doit déjà être associé à l’application Home Connect et connecté au même réseau que Jeedom. Le compte utilisé par l’application est un compte SingleKey.

Le ZIP obtenu contient des clés secrètes propres à vos appareils. Ne le publiez pas et ne l’ajoutez pas à un dépôt Git.

## Installation

1. Installez les dépendances depuis la page du plugin.
2. Ouvrez la configuration de LocalHomeConnect.
3. Renseignez l’identifiant et le mot de passe SingleKey, puis enregistrez la configuration.
4. Cliquez sur **Récupérer directement**. Tous les appareils associés au compte sont importés si SingleKey autorise ce parcours.
5. Si le plugin détecte hCaptcha ou une validation interactive, suivez la solution avec navigateur proposée automatiquement.
6. Démarrez le démon.
7. Depuis la page des équipements, cliquez sur **Découvrir** afin de résoudre les adresses par mDNS.

### Récupération directe et solution avec navigateur

Le serveur Jeedom commence par reproduire directement le parcours SingleKey. Il
conserve les cookies dans un fichier temporaire privé et n’envoie jamais les
identifiants dans les logs. Si le formulaire est protégé par hCaptcha ou demande
une interaction humaine, aucun contournement n’est tenté et la modale navigateur
s’ouvre automatiquement.

Dans cette solution de secours :

1. Jeedom crée le couple PKCE `code_verifier`/`code_challenge`, ouvre la session Home Connect et conserve le vérificateur ainsi que ses cookies dans son cache pendant 15 minutes ;
2. le navigateur est envoyé directement vers SingleKey ;
3. l’utilisateur s’authentifie et le navigateur exécute JavaScript, hCaptcha ou la validation supplémentaire demandée ;
4. sur la page SingleKey **Redirection…**, l’utilisateur ouvre la **Console** avec F12 puis clique lui-même sur **CONTINUER**, sans attendre le déclenchement automatique annoncé après 3 secondes ;
5. si la politique CSP de SingleKey bloque la requête, l’adresse `https://api.home-connect.com/security/oauth/redirect_target?...` affichée dans la console est recopiée dans Jeedom ;
6. Jeedom poursuit cette redirection côté serveur jusqu’à `hcauth://auth/prod`, vérifie l’état OAuth et échange le code éphémère ;
7. Jeedom récupère l’inventaire, les clés TLS ou AES et le ZIP IDDF de chaque appareil ;
8. Jeedom importe `_FeatureMapping.xml`, `_DeviceDescription.xml` et les métadonnées locales.

SingleKey refuse d’être affiché dans une iframe. La modale ouvre donc un nouvel
onglet, ce qui permet au navigateur de gérer entièrement son JavaScript et les
validations humaines. La redirection finale utilise un protocole d’application
que le navigateur ne sait généralement pas ouvrir : son adresse peut apparaître
dans la barre d’adresse ou seulement dans la console et doit être recopiée dans Jeedom. Aucun composant externe
n’est nécessaire. Sur mobile, l’application Home Connect peut intercepter cette
adresse ; un navigateur sur ordinateur est donc préférable. Un ZIP produit par
[Home Connect Profile Downloader](https://github.com/bruestel/homeconnect-profile-downloader)
avec la cible `openHAB` reste compatible, y compris pour plusieurs appareils.

La page SingleKey peut bloquer elle-même l’appel vers Home Connect avec un message
de console du type « Refused to connect to
`https://api.home-connect.com/security/oauth/redirect_target?...` because it does
not appear in the connect-src directive ». Copiez cette URL, ou même la ligne
complète, dans la modale : Jeedom extrait l’adresse, suit la redirection côté
serveur et récupère ensuite `hcauth://`. L’adresse
`singlekey-id.com/.../redirection?f=...` et le lien SingleKey
`auth/connect/authorize/callback` restent des étapes intermédiaires non acceptées.
Dans le comportement observé, cette URL n’apparaît ni dans **Réseau** ni dans
**Sources**. Elle est écrite uniquement dans la **Console**, après un clic manuel
sur **CONTINUER** ; le compte à rebours automatique ne déclenche pas l’erreur
exploitable.
Comme la session Home Connect est préparée par Jeedom avant l’ouverture du nouvel
onglet, l’utilisateur ne doit plus passer par un premier écran de connexion Home
Connect avant SingleKey.

Si mDNS ne traverse pas vos VLAN, renseignez directement l’adresse IP ou le nom DNS dans l’équipement puis sauvegardez et relancez le démon.

## Fonctionnement

Le démon choisit le transport indiqué dans chaque profil :

- AES-CBC/HMAC sur le port 80 pour les appareils plus anciens ;
- TLS 1.2 avec clé prépartagée sur le port 443 pour les appareils récents.

Pour un profil TLS qui contient aussi un vecteur AES valide, le démon essaie d’abord TLS puis bascule automatiquement sur AES si la négociation échoue.

La connexion reste ouverte et l'appareil transmet ses changements en temps réel. Il n'y a donc pas d'intervalle de rafraîchissement à régler ; le bouton **Rafraîchir** force seulement une relecture complète lorsque cela est nécessaire. Une session locale chiffrée et authentifiée est considérée comme en ligne même si certaines ressources `/ro/*`, facultatives selon les appareils, sont absentes. Seul un appareil réellement hors ligne bloque les actions. Si le démon a été arrêté, une actualisation, un test, une découverte ou une action tente d'abord de le relancer automatiquement.

Les commandes Jeedom sont générées depuis `FeatureMapping.xml`, `DeviceDescription.xml` et les descriptions envoyées en direct par l’appareil. Une action n’apparaît que si la valeur est réellement accessible en écriture, y compris dans le contexte du programme sélectionné.

Le widget LocalHomeConnect présente une sélection lisible des états, programmes, réglages utiles, entretiens et consommations. Les capacités plus rares ou techniques restent accessibles dans l’onglet **Commandes** de l’équipement. Sélectionnez **Widget du core Jeedom** dans l’équipement si vous préférez afficher toutes les commandes visibles sans cette sélection.

Le four dispose d’une vue **Cuisson** limitée aux éléments directement nécessaires : choix et démarrage du programme, température, minuteur, état, temps restant et progression. Les réglages généraux et les alertes sont placés sur des pages séparées.

Pour une table de cuisson, chaque foyer est regroupé dans une seule carte avec son état, son niveau de puissance, son programme éventuel et son temps restant. Les zones flexibles inactives et les informations de géométrie sont masquées. Les commandes de ventilation intégrée disposent de leur propre page lorsqu’elles existent.

## Programmes et options

Le widget propose les programmes disponibles et les options annoncées par l’appareil : température, essorage, fin différée, mode éco, etc. Au démarrage, seules les options connues du programme actuellement sélectionné sont envoyées. Les informations de progression, de durée et de consommation restent de simples mesures.

Le démarrage distant peut être refusé lorsque la porte est ouverte, qu’un programme tourne déjà ou que le contrôle à distance n’a pas été autorisé physiquement sur l’appareil.

## Sécurité

Le mot de passe SingleKey est chiffré par le core Jeedom et n’est transmis ni au
démon ni aux logs. Pour la solution navigateur, le vérificateur PKCE ne quitte pas
le serveur, expire après 15 minutes et ne peut être utilisé qu’une fois. Le code
OAuth, le jeton, les cookies et les ZIP temporaires sont supprimés après le traitement.
Les clés sont enregistrées dans `plugins/localhomeconnect/data`, hors de Git et
protégé contre l’accès HTTP, avec des permissions restrictives. Elles ne sont pas
affichées dans les équipements et ne sont pas journalisées.

Les fonctions susceptibles de couper définitivement la communication ou d’endommager la configuration sont filtrées : réinitialisation usine ou réseau, désactivation Wi-Fi, suppression d’appareil et mise à jour logicielle.

## Diagnostic

La page **Santé** indique l'état des dépendances, du démon et de chaque équipement. Elle précise notamment le transport réellement utilisé, l'état en ligne ou hors ligne, le nombre de reconnexions, les capacités reçues, la mémoire du démon et sa durée de fonctionnement. Le bouton placé après **Dernière erreur** teste la communication complète avec l'appareil.

Lors d'une synchronisation, une commande générée n'est jamais supprimée automatiquement. Si sa capacité disparaît réellement du nouveau profil XML, ou si elle n'est plus inscriptible, elle est masquée et marquée **Obsolète** afin de préserver les scénarios Jeedom existants. Une capacité seulement absente de l'état courant ou des options du programme sélectionné n'est pas déclarée obsolète. Elle est restaurée automatiquement si elle réapparaît dans un profil ultérieur.

Les profils sont installés de manière transactionnelle dans le répertoire privé du plugin. Ils peuvent être supprimés depuis la configuration uniquement lorsqu'aucun équipement, même désactivé, ne les utilise. Le mot de passe SingleKey, le jeton du démon et l'état temporaire OAuth sont chiffrés par le core Jeedom ; les clés des appareils ne sont pas recopiées dans la configuration du démon.

En cas d’échec :

- vérifiez que l’appareil est allumé ou que son module réseau reste actif ;
- vérifiez l’adresse et l’accès aux ports 80 ou 443 ;
- relancez la découverte mDNS ;
- réimportez un profil récent si l’appareil a été réinitialisé ou réassocié.

Si les logs indiquent uniquement un échec du callback HTTPS vers Jeedom et que l’adresse interne utilise un certificat autosigné, désactivez temporairement **Vérifier le certificat HTTPS de Jeedom**. Cette option n’agit pas sur le chiffrement Home Connect lui-même.

## Compatibilité

La prise en charge n’est pas limitée à une liste figée de modèles. Elle repose sur le profil propre à chaque appareil : lave-vaisselle, lave-linge, sèche-linge, four, table de cuisson, hotte, machine à café, réfrigérateur, congélateur et autres familles Home Connect peuvent ainsi exposer des commandes différentes.

Une fonction absente du profil ou annoncée uniquement en lecture ne peut pas être pilotée de façon fiable et reste donc une commande d’information.
