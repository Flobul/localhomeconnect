<?php

if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
}
?>
<div id="div_localhomeconnect_profile_authorization" class="localhomeconnect-authorization">
    <div class="alert alert-info">
        <i class="fas fa-shield-alt"></i>
        {{La connexion est effectuée directement dans votre navigateur. Jeedom ne reçoit et n’enregistre jamais votre mot de passe SingleKey.}}
    </div>

    <div class="localhomeconnect-authorization-step">
        <span class="localhomeconnect-authorization-number">1</span>
        <div>
            <h4>{{Ouvrir SingleKey}}</h4>
            <p>{{Connectez-vous normalement et terminez le CAPTCHA ou la validation demandée.}}</p>
            <a href="#" target="_blank" rel="noopener noreferrer" class="btn btn-primary disabled" id="bt_localhomeconnect_open_singlekey" aria-disabled="true">
                <i class="fas fa-sync fa-spin"></i> {{Préparation de l’autorisation...}}
            </a>
            <p class="help-block">{{Jeedom a déjà préparé la session Home Connect. SingleKey interdit son affichage à l’intérieur de Jeedom : seule sa page de connexion s’ouvre donc dans un nouvel onglet.}}</p>
        </div>
    </div>

    <div class="localhomeconnect-authorization-step">
        <span class="localhomeconnect-authorization-number">2</span>
        <div>
            <h4>{{Terminer la redirection SingleKey}}</h4>
            <p><strong>{{N’attendez pas la redirection automatique annoncée après 3 secondes : ouvrez la Console puis cliquez vous-même sur CONTINUER.}}</strong> {{Cette page est une étape intermédiaire : son adresse singlekey-id.com/redirection ne contient pas encore le code OAuth attendu.}}</p>
            <p class="help-block"><i class="fas fa-desktop"></i> {{Effectuez de préférence cette opération depuis un navigateur sur ordinateur. Sur mobile, l’application Home Connect peut intercepter cette adresse.}}</p>
        </div>
    </div>

    <div class="localhomeconnect-authorization-step">
        <span class="localhomeconnect-authorization-number">3</span>
        <div>
            <h4>{{Copier la redirection bloquée}}</h4>
            <p>{{Dans la Console, copiez la ligne « Refused to connect » contenant l’adresse https://api.home-connect.com/security/oauth/redirect_target?code=… Vous pouvez coller la ligne entière : Jeedom en extraira l’adresse et terminera lui-même la redirection.}}</p>
            <details class="localhomeconnect-authorization-help">
                <summary><i class="fas fa-tools"></i> {{Procédure exacte dans la Console}}</summary>
                <ol>
                    <li>{{Ouvrez les outils de développement avec F12 avant de cliquer sur CONTINUER.}}</li>
                    <li>{{Sélectionnez l’onglet Console. L’adresse n’apparaît pas dans Réseau ni dans Sources.}}</li>
                    <li>{{Cliquez manuellement sur CONTINUER ; le déclenchement automatique après 3 secondes ne produit pas l’erreur exploitable.}}</li>
                    <li>{{Repérez « Refused to connect to https://api.home-connect.com/security/oauth/redirect_target… » et copiez cette URL ou la ligne complète.}}</li>
                </ol>
                <p class="help-block">{{Si votre navigateur affiche directement une adresse hcauth://auth/prod?code=…, elle reste également acceptée. Ne collez pas l’adresse singlekey-id.com/redirection ni le lien singlekey-id.com/auth/connect/authorize/callback.}}</p>
            </details>
            <label for="in_localhomeconnect_authorization_redirect">{{Redirection Home Connect complète}}</label>
            <textarea class="form-control" rows="4" id="in_localhomeconnect_authorization_redirect" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="https://api.home-connect.com/security/oauth/redirect_target?code=...&amp;state=..."></textarea>
            <button type="button" class="btn btn-success" id="bt_localhomeconnect_complete_authorization" disabled>
                <i class="fas fa-cloud-download-alt"></i> {{Récupérer les profils et les clés}}
            </button>
        </div>
    </div>

    <div class="alert alert-warning localhomeconnect-authorization-expiry">
        <i class="fas fa-clock"></i>
        {{Pour votre sécurité, cette autorisation expire après 15 minutes et ne peut être utilisée qu’une fois.}}
    </div>
</div>
<?php include_file('desktop', 'profile.authorization', 'js', 'localhomeconnect'); ?>
