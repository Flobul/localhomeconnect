# LocalHomeConnect pour Jeedom

LocalHomeConnect dialogue directement sur le réseau local avec les appareils Bosch, Siemens et BSH compatibles Home Connect. Le cloud n'est pas utilisé pendant le fonctionnement courant.

La connexion WebSocket reste ouverte et les changements sont reçus au fil de l’eau : aucun intervalle d’interrogation n’est nécessaire.

## Prérequis

Jeedom 4.4 ou plus récent et Debian 12 sont requis. Une version compatible de Node.js 20 ou plus récent est gérée par `NebzHB/dependance.lib` lors de l’installation des dépendances.

1. Associer les appareils à l'application Home Connect.
2. Installer les dépendances du plugin.
3. Enregistrer le compte SingleKey dans la configuration, puis cliquer sur **Récupérer directement**.
4. Lancer le démon et la découverte.

Jeedom tente d’abord de reproduire directement le parcours OAuth PKCE Home
Connect/SingleKey. Si SingleKey impose hCaptcha ou une validation interactive,
le plugin ouvre automatiquement la solution de secours dans le navigateur. À la
fin de ce parcours, l’utilisateur recopie dans la fenêtre Jeedom la requête Home
Connect bloquée affichée dans la console, ou l’adresse `hcauth://auth/prod` si le
navigateur l’affiche directement.

Lorsque SingleKey affiche **Redirection…**, ouvrez la **Console** avec F12 puis
cliquez vous-même sur **CONTINUER**, sans attendre le compte à rebours de 3 secondes.
Si sa politique CSP bloque l’appel vers Home Connect, la modale accepte directement
la ligne de console contenant
`https://api.home-connect.com/security/oauth/redirect_target?code=…`. Jeedom en
extrait l’URL et poursuit côté serveur jusqu’à `hcauth://`. Cette URL est observée
uniquement dans la Console, pas dans Réseau ni dans Sources. L’adresse
`singlekey-id.com/.../redirection` n’est qu’une étape intermédiaire et ne contient
pas encore le code OAuth utilisable.

Pour cette solution de secours, Jeedom prépare d’abord la session Home Connect et
conserve ses cookies côté serveur. Le nouvel onglet s’ouvre ensuite directement
sur SingleKey, ce qui évite deux parcours de connexion successifs.

SingleKey interdit son intégration dans une iframe. La solution de secours ouvre
donc un nouvel onglet afin que le navigateur exécute JavaScript, hCaptcha et les
validations humaines. L'import manuel d'un ZIP généré par
[Home Connect Profile Downloader](https://github.com/bruestel/homeconnect-profile-downloader)
reste disponible.

Le mot de passe SingleKey est chiffré par le core Jeedom et n’est transmis ni au
démon ni aux logs. Le vérificateur PKCE, le code et le jeton OAuth sont éphémères. Le ZIP contient les clés propres aux
appareils : son contenu utile est extrait dans le répertoire privé du plugin avec
des permissions restrictives ; ses secrets ne sont ni affichés dans Jeedom ni
écrits dans les logs.

## Compatibilité

Deux transports existent selon le profil :

- AES sur `ws://appareil:80/homeconnect` ;
- TLS-PSK sur `wss://appareil:443/homeconnect`.

Les appareils qui n'exposent leur interface réseau que lorsqu'ils sont allumés restent visibles dans Jeedom et se reconnectent automatiquement.

## Sécurité des actions

Une action n'est créée que si l'appareil annonce la propriété en écriture. Les commandes de réinitialisation, de désactivation réseau, de téléchargement et de mise à jour logicielle sont volontairement bloquées.

## Sources techniques

- [osresearch/hcpy](https://github.com/osresearch/hcpy)
- [chris-mc1/homeconnect_local_hass](https://github.com/chris-mc1/homeconnect_local_hass)
- [dosordie/ioBroker.homeconnect-local](https://github.com/dosordie/ioBroker.homeconnect-local)
- [eifel-tech/ioBroker.cloudless-homeconnect](https://github.com/eifel-tech/ioBroker.cloudless-homeconnect)
