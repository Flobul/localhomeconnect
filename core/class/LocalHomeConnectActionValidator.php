<?php

/**
 * Valide les valeurs fournies à une action avant leur transmission au démon.
 *
 * Le démon refait les contrôles à partir du XML privé. Cette première barrière
 * fournit surtout une erreur Jeedom immédiate et empêche les appels incomplets
 * provenant d'un scénario ou de l'API.
 */
class LocalHomeConnectActionValidator
{
    /**
     * Extrait et valide la valeur d'une commande d'écriture.
     *
     * @param cmd $command Commande Jeedom.
     * @param array<string,mixed> $options Options d'exécution.
     * @return mixed
     */
    public static function writeValue($command, $options)
    {
        if ((int) $command->getConfiguration('has_fixed_value', 0) === 1) {
            return $command->getConfiguration('fixed_value');
        }
        $subType = (string) $command->getSubType();
        if ($subType === 'select') {
            if (!array_key_exists('select', $options) || $options['select'] === null) {
                throw new InvalidArgumentException(__('Sélectionnez une valeur avant d’exécuter cette commande', __FILE__));
            }
            $value = (string) $options['select'];
            $allowed = self::listValues((string) $command->getConfiguration('listValue', ''));
            if (count($allowed) > 0 && !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException(__('La valeur sélectionnée n’est pas autorisée par le profil XML', __FILE__));
            }
            return $value;
        }
        if ($subType === 'slider') {
            if (!array_key_exists('slider', $options) || !is_numeric($options['slider'])) {
                throw new InvalidArgumentException(__('Renseignez une valeur numérique avant d’exécuter cette commande', __FILE__));
            }
            $value = (float) $options['slider'];
            if (!is_finite($value)) {
                throw new InvalidArgumentException(__('La valeur numérique n’est pas valide', __FILE__));
            }
            self::assertNumericRange($command, $value);
            return $value;
        }
        if ($subType === 'message') {
            if (array_key_exists('message', $options)) {
                return (string) $options['message'];
            }
            if (array_key_exists('title', $options)) {
                return (string) $options['title'];
            }
            throw new InvalidArgumentException(__('Renseignez la valeur attendue avant d’exécuter cette commande', __FILE__));
        }
        throw new RuntimeException(__('Cette commande Home Connect attend une valeur qui n’est pas définie par son profil XML', __FILE__));
    }

    /**
     * Valide un identifiant de programme issu de la liste Jeedom.
     *
     * @param cmd $command Commande de sélection.
     * @param array<string,mixed> $options Options d'exécution.
     * @return int
     */
    public static function program($command, $options)
    {
        if (!array_key_exists('select', $options) || !is_numeric($options['select'])) {
            throw new InvalidArgumentException(__('Sélectionnez un programme avant d’exécuter cette commande', __FILE__));
        }
        $raw = (string) $options['select'];
        $allowed = self::listValues((string) $command->getConfiguration('listValue', ''));
        if (count($allowed) > 0 && !in_array($raw, $allowed, true)) {
            throw new InvalidArgumentException(__('Le programme sélectionné n’est plus disponible sur cet appareil', __FILE__));
        }
        $program = (int) $options['select'];
        if ($program <= 0) {
            throw new InvalidArgumentException(__('L’identifiant du programme est invalide', __FILE__));
        }
        return $program;
    }

    /**
     * Retourne les valeurs brutes d'une liste Jeedom `valeur|libellé`.
     *
     * @param string $listValue Liste sérialisée.
     * @return string[]
     */
    private static function listValues($listValue)
    {
        $values = array();
        foreach (explode(';', (string) $listValue) as $entry) {
            if ($entry === '') {
                continue;
            }
            $separator = strpos($entry, '|');
            $values[] = $separator === false ? $entry : substr($entry, 0, $separator);
        }
        return array_values(array_unique($values));
    }

    /**
     * Contrôle bornes et pas configurés depuis le XML.
     *
     * @param cmd $command Commande curseur.
     * @param float $value Valeur reçue.
     * @return void
     */
    private static function assertNumericRange($command, $value)
    {
        $minimum = $command->getConfiguration('minValue', '');
        $maximum = $command->getConfiguration('maxValue', '');
        $step = $command->getConfiguration('step', '');
        if ($minimum !== '' && is_numeric($minimum) && $value < (float) $minimum) {
            throw new InvalidArgumentException(sprintf(__('La valeur doit être supérieure ou égale à %s', __FILE__), $minimum));
        }
        if ($maximum !== '' && is_numeric($maximum) && $value > (float) $maximum) {
            throw new InvalidArgumentException(sprintf(__('La valeur doit être inférieure ou égale à %s', __FILE__), $maximum));
        }
        if ($step !== '' && is_numeric($step) && (float) $step > 0) {
            $base = $minimum !== '' && is_numeric($minimum) ? (float) $minimum : 0.0;
            $quotient = ($value - $base) / (float) $step;
            if (abs($quotient - round($quotient)) > 0.0000001) {
                throw new InvalidArgumentException(sprintf(__('La valeur doit respecter le pas %s', __FILE__), $step));
            }
        }
    }
}
