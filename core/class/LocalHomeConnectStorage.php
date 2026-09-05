<?php

/** Protège et répare le stockage privé, sans suivre les liens symboliques. */
class LocalHomeConnectStorage
{
    /**
     * Rétablit les droits des répertoires (0700), des fichiers (0600) et le refus HTTP.
     * Les fichiers existants et leurs contenus sont conservés.
     *
     * @param string $root Répertoire de données du plugin.
     * @return array{state:bool,message:string}
     */
    public static function repair($root)
    {
        $problems = array();
        clearstatcache(true);
        if (is_link($root)) {
            $problems[] = __('Lien symbolique interdit sur le stockage privé', __FILE__);
        } elseif (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            $problems[] = __('Création du répertoire de données impossible', __FILE__);
        } else {
            self::repairEntry($root, $problems);
            $htaccess = $root . '/.htaccess';
            if (!is_link($htaccess) && (!file_exists($htaccess) || is_file($htaccess))) {
                $content = is_file($htaccess) ? @file_get_contents($htaccess) : '';
                if ($content !== false && !preg_match('/^\s*Deny\s+from\s+all\s*$/mi', $content)) {
                    // Conserve les éventuelles directives personnalisées.
                    $content = "Deny from all\n" . $content;
                    if (@file_put_contents($htaccess, $content, LOCK_EX) !== strlen($content)) {
                        $problems[] = __('Écriture de la protection HTTP impossible', __FILE__);
                    }
                }
                if (is_file($htaccess)) {
                    self::repairPermissions($htaccess, 0600, $problems);
                }
            }
            $protection = !is_link($htaccess) && is_file($htaccess) ? @file_get_contents($htaccess) : false;
            if ($protection === false || !preg_match('/^\s*Deny\s+from\s+all\s*$/mi', $protection)) {
                $problems[] = __('protection HTTP absente', __FILE__);
            }
        }
        $problems = array_values(array_unique($problems));
        return array('state' => count($problems) === 0, 'message' => implode(', ', $problems));
    }

    /** Répare chaque entrée réelle, y compris les fichiers cachés et temporaires. */
    private static function repairEntry($path, &$problems)
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            $problems[] = sprintf(__('Lien symbolique non corrigé sur %s', __FILE__), basename($path));
            return;
        }
        if (is_dir($path)) {
            self::repairPermissions($path, 0700, $problems);
            $entries = @scandir($path);
            if ($entries === false) {
                $problems[] = sprintf(__('Lecture du répertoire impossible : %s', __FILE__), basename($path));
                return;
            }
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::repairEntry($path . '/' . $entry, $problems);
                }
            }
        } elseif (is_file($path)) {
            self::repairPermissions($path, 0600, $problems);
        } elseif (file_exists($path)) {
            $problems[] = sprintf(__('Type de fichier inattendu : %s', __FILE__), basename($path));
        }
    }

    /** Vérifie les droits après correction pour ne pas masquer un échec de chmod. */
    private static function repairPermissions($path, $mode, &$problems)
    {
        $permissions = @fileperms($path);
        if ($permissions !== false && ($permissions & 07777) === $mode) {
            return;
        }
        @chmod($path, $mode);
        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        // Un fichier temporaire peut avoir été supprimé par le démon entre-temps.
        if ($permissions === false && !file_exists($path)) {
            return;
        }
        if ($permissions === false || ($permissions & 07777) !== $mode) {
            $problems[] = sprintf(__('Permissions impossibles à corriger sur %s', __FILE__), basename($path));
        }
    }
}
