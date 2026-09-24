<?php

namespace App\Notification;

/**
 * Sons de notification proposés au membre (paramètres du profil).
 *
 * Les sons sont SYNTHÉTISÉS dans le navigateur (Web Audio, assets/lib/sounds.js) : aucun fichier audio,
 * rien à télécharger, et des sons propres au site. Les clés doivent correspondre à celles de sounds.js.
 */
final class NotificationSound
{
    public const DEFAULT = 'auspex';
    public const NONE = 'none';

    /** @var array<string, array{label: string, description: string}> */
    public const SOUNDS = [
        'auspex' => ['label' => 'Auspex', 'description' => 'Double écho de scanner, comme un contact radar détecté.'],
        'forge' => ['label' => 'Enclume', 'description' => 'Un coup de marteau clair sur l\'enclume de la forge.'],
        'des' => ['label' => 'Jet de dés', 'description' => 'Trois dés qui roulent et s\'arrêtent sur la table.'],
        'cor' => ['label' => 'Cor de guerre', 'description' => 'Un appel de cor bref et grave.'],
        'cristal' => ['label' => 'Cristal psychique', 'description' => 'Un tintement scintillant et légèrement irréel.'],
        'servo' => ['label' => 'Servo-crâne', 'description' => 'Une série de bips mécaniques de servo-crâne.'],
        self::NONE => ['label' => 'Aucun son', 'description' => 'Les notifications restent silencieuses.'],
    ];

    /** Choix du formulaire : libellé => clé. */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::SOUNDS as $key => $sound) {
            $choices[$sound['label']] = $key;
        }

        return $choices;
    }

    public static function isValid(string $key): bool
    {
        return isset(self::SOUNDS[$key]);
    }
}
