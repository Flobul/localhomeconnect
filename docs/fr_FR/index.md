# LocalHomeConnect

LocalHomeConnect permet de suivre et de piloter vos appareils Home Connect depuis Jeedom. Une fois les appareils ajoutés, Jeedom communique avec eux directement sur votre réseau local.

## Avant de commencer

Vous devez disposer de :

- Jeedom 4.4 ou plus récent, sous Debian 12 ;
- appareils déjà ajoutés dans l’application Home Connect et connectés au même réseau que Jeedom ;
- vos identifiants SingleKey, utilisés pour vous connecter à Home Connect.

Les fonctions disponibles dépendent de chaque appareil : suivi d’un programme, température, consommation, réglages ou démarrage à distance.

## Ajouter vos appareils

1. Depuis la page du plugin, lancez l’installation des **dépendances** et attendez qu’elle soit terminée.
2. Dans la configuration, renseignez vos identifiants SingleKey dans la carte **Connexion automatique**, puis enregistrez.
3. Cliquez sur **Récupérer automatiquement**. Le plugin récupère les profils, c’est-à-dire les informations nécessaires pour se connecter à vos appareils.
4. Si une connexion dans le navigateur est proposée, suivez les étapes ci-dessous.
5. Vérifiez que vos appareils apparaissent dans **Profils installés**.
6. Démarrez le **démon**, le service qui assure la communication avec les appareils.
7. Ouvrez la page des équipements et cliquez sur **Découvrir** pour retrouver vos appareils sur le réseau.

### Si une connexion dans le navigateur est nécessaire

Utilisez de préférence un ordinateur pour cette étape. Vous pouvez aussi lancer ce parcours avec **Ouvrir la connexion SingleKey**.

1. Connectez-vous dans la nouvelle page et effectuez la vérification demandée, s’il y en a une.
2. Sur la page **Redirection…**, ouvrez les outils du navigateur avec **F12**, puis l’onglet **Console**.
3. Cliquez vous-même sur **CONTINUER**, sans attendre la fin du compte à rebours.
4. Copiez la ligne d’erreur contenant `redirect_target` et collez-la dans la fenêtre Jeedom pour terminer la récupération.

La ligne d’erreur complète convient : vous n’avez pas besoin d’en extraire l’adresse. Suivez les indications affichées dans la fenêtre Jeedom.

### Si vous avez déjà un fichier ZIP de profils

Dans la carte **Home Connect Profile Downloader**, sélectionnez votre fichier et cliquez sur **Importer le ZIP**, sans le décompresser. Vous pouvez obtenir ce fichier avec [Home Connect Profile Downloader](https://github.com/bruestel/homeconnect-profile-downloader), en choisissant la cible **openHAB**.

## Utilisation au quotidien

Les informations se mettent à jour automatiquement. Depuis le widget d’un appareil, vous pouvez consulter son état, suivre un programme et utiliser les réglages disponibles.

Pour démarrer un programme à distance, vérifiez que la porte est fermée et que le contrôle à distance est autorisé sur l’appareil. Certaines actions peuvent être indisponibles pendant un programme.

Les commandes supplémentaires se trouvent dans l’onglet **Commandes** de l’équipement. L’option **Widget du core Jeedom** permet d’utiliser l’affichage standard de Jeedom si vous le préférez.

Les réglages **Communication locale** et **Diagnostic et sécurité** peuvent généralement conserver leurs valeurs par défaut. Modifiez-les uniquement pour un besoin particulier ou à la demande du support.

## Vérifier que tout fonctionne

Ouvrez **Santé** depuis la page des équipements :

- **Équipements** indique quels appareils sont prêts, en cours de connexion ou hors ligne. Le bouton **Tester la communication** permet de vérifier un appareil.
- **Contrôles du plugin** indique si le plugin est prêt à fonctionner. En cas de **NOK**, lisez le conseil affiché.

Cliquez sur **Rafraîchir** pour actualiser cette page. La recherche permet de retrouver rapidement un appareil ou un contrôle.

## En cas de problème

| Problème | Que faire ? |
| --- | --- |
| Un appareil n’est pas trouvé ou reste hors ligne | Vérifiez qu’il est allumé, connecté au réseau et accessible dans Home Connect, puis relancez **Découvrir**. |
| La connexion automatique au compte échoue | Utilisez **Ouvrir la connexion SingleKey** et suivez les étapes de connexion dans le navigateur. |
| Un programme refuse de démarrer | Vérifiez la porte, l’autorisation de contrôle à distance et qu’aucun autre programme n’est déjà en cours. |
| L’appareil a été réinitialisé ou ajouté à nouveau dans Home Connect | Récupérez de nouveau ses profils depuis la configuration. |
| Santé affiche **Permissions à corriger** pour le stockage privé | Cliquez sur **Rafraîchir** : le plugin tente de corriger automatiquement la protection de ses fichiers. |

Si le problème persiste, utilisez le lien **Communauté** dans la configuration du plugin. Précisez le modèle de l’appareil, le problème rencontré et le message affiché dans **Santé**.

## Vos données

Le plugin protège vos identifiants et les informations de connexion de vos appareils. Il vérifie et répare automatiquement la protection de ses fichiers lorsque cela est nécessaire.

**Conservez les ZIP de profils à l’abri et ne les partagez pas** : ils contiennent les informations permettant de se connecter à vos appareils.
