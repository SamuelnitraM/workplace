<?php

namespace App\Twig;

use App\Forum\ForumMarkdown;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtres du forum :
 *   {{ post.content|forum_markdown }}           HTML sûr (voir App\Forum\ForumMarkdown)
 *   {{ post.content|forum_excerpt(150) }}        texte brut court pour les aperçus
 */
final class ForumMarkdownExtension extends AbstractExtension
{
    public function __construct(private readonly ForumMarkdown $markdown)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('forum_markdown', [$this->markdown, 'toHtml'], ['is_safe' => ['html']]),
            new TwigFilter('forum_excerpt', [$this->markdown, 'toExcerpt']),
        ];
    }
}
