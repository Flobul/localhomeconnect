# LocalHomeConnect pour Jeedom

Retrouvez vos appareils Bosch, Siemens et autres marques du groupe BSH compatibles Home Connect dans Jeedom : four, lave-vaisselle, lave-linge, table de cuisson…

Suivez leur état, recevez leurs mises à jour en temps réel et utilisez les commandes disponibles selon le modèle. Après la configuration initiale, Jeedom communique directement avec vos appareils sur votre réseau local, sans passer par le cloud Home Connect.

## Avant de commencer

- Jeedom 4.4 ou plus récent, sous Debian 12.
- Des appareils déjà associés à l’application Home Connect.
- Vos identifiants SingleKey, utilisés pour vous connecter à Home Connect.
- Jeedom et les appareils connectés au même réseau local.

## Démarrage rapide

1. Installez le plugin et ses **dépendances** depuis Jeedom.
2. Dans la configuration du plugin, renseignez vos identifiants SingleKey et enregistrez.
3. Cliquez sur **Récupérer automatiquement** pour ajouter vos appareils. Si une connexion dans le navigateur est demandée, suivez les instructions affichées.
4. Démarrez le **démon**, le service qui assure la communication avec vos appareils.
5. Sur la page des équipements, cliquez sur **Découvrir** pour les retrouver sur votre réseau.

Pour les étapes détaillées ou les autres méthodes d’ajout, consultez la [documentation complète](docs/fr_FR/index.md).

## Au quotidien

Les états se mettent à jour automatiquement. Les commandes proposées dépendent de chaque appareil et de ses fonctions disponibles. Le démarrage à distance peut nécessiter une autorisation sur l’appareil lui-même.

En cas de difficulté, ouvrez **Santé** pour vérifier les connexions et utilisez **Tester la communication** sur l’appareil concerné. Le plugin corrige automatiquement les permissions de son stockage privé lorsque cela est possible.

## Besoin d’aide ?

- [Documentation et dépannage](docs/fr_FR/index.md)
- [Historique des versions](CHANGELOG.md)
- [Communauté Jeedom](https://community.jeedom.com/tag/plugin-localhomeconnect)
