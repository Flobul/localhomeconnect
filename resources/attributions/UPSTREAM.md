# Projets de référence

Le protocole local Home Connect n'est pas documenté publiquement par BSH. Cette implémentation indépendante s'appuie sur les travaux sous licence MIT suivants :

- `osresearch/hcpy` — transport WebSocket AES-CBC/HMAC et séquence protocolaire ;
- `chris-mc1/homeconnect_websocket` et `homeconnect_local_hass` — description des appareils et comportement des entités ;
- `dosordie/ioBroker.homeconnect-local` — TLS-PSK sous Node.js, découverte mDNS et règles prudentes d'écriture.
- `eifel-tech/ioBroker.cloudless-homeconnect` — parcours OAuth PKCE Home Connect/SingleKey côté serveur et récupération des profils IDDF.
- `NebzHB/dependance.lib` — installation contrôlée de la version Node.js requise dans l’environnement Jeedom.

Les notices MIT des projets sont conservées dans ce répertoire. Le code du plugin reste distribué sous AGPL-3.0-or-later.
