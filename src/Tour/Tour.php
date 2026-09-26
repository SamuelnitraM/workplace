<?php

namespace App\Tour;

/**
 * Guided tour of one feature: the page it runs on and its steps.
 * A step highlights the first visible element matching its selector list; without element, or when none is
 * visible (mobile layout, empty page), its explanation is shown in the middle of the screen.
 */
final readonly class Tour
{
    /**
     * @param array<string, string>                                     $routeParameters "@me" stands for the username of the member
     * @param list<array{element: ?string, title: string, text: string}> $steps
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $summary,
        public string $icon,
        public string $route,
        public array $steps,
        public array $routeParameters = [],
    ) {
    }
}
