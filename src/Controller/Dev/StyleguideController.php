<?php

namespace App\Controller\Dev;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Guide de style vivant du design system (docs/design-system.md).
 * Route déclarée uniquement dans l'environnement « dev » : inexistante en production.
 */
final class StyleguideController extends AbstractController
{
    /** Valeurs des tokens (doivent refléter @theme dans assets/styles/app.css). */
    private const SURFACES = [
        'canvas' => '#0d1016',
        'surface' => '#151a23',
        'surface-raised' => '#1c222d',
        'overlay' => '#242b38',
    ];

    private const TEXTS = [
        'fg' => '#e9ecf2',
        'fg-secondary' => '#b3bccb',
        'fg-muted' => '#8e98aa',
        'primary-text' => '#a5b4fc',
        'accent-text' => '#e0b25c',
        'success-text' => '#4ade80',
        'warning-text' => '#fbbf24',
        'danger-text' => '#f97a7a',
        'info-text' => '#7dd3fc',
    ];

    /** [fond, texte posé dessus] */
    private const SOLIDS = [
        'primary' => ['#4f46e5', '#ffffff'],
        'primary-hover' => ['#5d55ee', '#ffffff'],
        'accent' => ['#d4a24c', '#17120a'],
        'accent-hover' => ['#e0b25c', '#17120a'],
        'success' => ['#15803d', '#ffffff'],
        'success-hover' => ['#166534', '#ffffff'],
        'warning' => ['#f59e0b', '#17120a'],
        'danger' => ['#dc2626', '#ffffff'],
        'danger-hover' => ['#b91c1c', '#ffffff'],
        'info' => ['#0369a1', '#ffffff'],
        'notify' => ['#e11d48', '#ffffff'],
    ];

    /** Fonds teintés (couleur, opacité) × texte sémantique correspondant */
    private const SOFTS = [
        'primary' => ['#6366f1', 0.16, '#a5b4fc'],
        'accent' => ['#d4a24c', 0.14, '#e0b25c'],
        'success' => ['#22c55e', 0.14, '#4ade80'],
        'warning' => ['#f59e0b', 0.14, '#fbbf24'],
        'danger' => ['#ef4444', 0.14, '#f97a7a'],
        'info' => ['#38bdf8', 0.14, '#7dd3fc'],
    ];

    #[Route('/_styleguide', name: 'app_styleguide', env: 'dev')]
    public function __invoke(): Response
    {
        $texts = [];
        foreach (self::TEXTS as $name => $hex) {
            $ratios = [];
            foreach (self::SURFACES as $bgName => $bg) {
                $ratios[$bgName] = self::contrast($hex, $bg);
            }
            $texts[] = ['name' => $name, 'hex' => $hex, 'ratios' => $ratios];
        }

        $solids = [];
        foreach (self::SOLIDS as $name => [$bg, $fg]) {
            $solids[] = ['name' => $name, 'bg' => $bg, 'fg' => $fg, 'ratio' => self::contrast($fg, $bg)];
        }

        $softs = [];
        foreach (self::SOFTS as $name => [$color, $alpha, $text]) {
            $onSurface = self::blend($color, $alpha, self::SURFACES['surface']);
            $onOverlay = self::blend($color, $alpha, self::SURFACES['overlay']);
            $softs[] = [
                'name' => $name,
                'text' => $text,
                'surface' => self::contrast($text, $onSurface),
                'fg' => self::contrast(self::TEXTS['fg'], $onSurface),
                'overlay' => self::contrast($text, $onOverlay),
            ];
        }

        return $this->render('styleguide/index.html.twig', [
            'surfaces' => self::SURFACES,
            'texts' => $texts,
            'solids' => $solids,
            'softs' => $softs,
            'ui_contrast' => [
                'line-strong / surface' => self::contrast('#5a647a', self::SURFACES['surface']),
                'line-strong / canvas' => self::contrast('#5a647a', self::SURFACES['canvas']),
                'focus / canvas' => self::contrast('#a5b4fc', self::SURFACES['canvas']),
                'focus / surface' => self::contrast('#a5b4fc', self::SURFACES['surface']),
            ],
        ]);
    }

    /** Ratio de contraste WCAG 2.x entre deux couleurs hexadécimales. */
    private static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** Couleur $color à l'opacité $alpha posée sur $bg (résultat opaque). */
    private static function blend(string $color, float $alpha, string $bg): string
    {
        $c = self::rgb($color);
        $b = self::rgb($bg);
        $out = '#';
        for ($i = 0; $i < 3; ++$i) {
            $out .= sprintf('%02x', (int) round($c[$i] * $alpha + $b[$i] * (1 - $alpha)));
        }

        return $out;
    }
}
