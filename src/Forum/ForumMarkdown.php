<?php

namespace App\Forum;

use App\Text\MentionResolver;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Mention\Generator\MentionGeneratorInterface;
use League\CommonMark\Extension\Mention\Mention;
use League\CommonMark\Extension\Mention\MentionExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\AbstractInline;
use League\CommonMark\Node\Inline\Text;

/**
 * Rendu Markdown des messages du forum (CommonMark + barré + liens automatiques + mentions @pseudo).
 *
 * Sécurité :
 *  - le HTML saisi est ÉCHAPPÉ (html_input = escape), les liens javascript:/data: sont neutralisés ;
 *  - les liens externes s'ouvrent dans un nouvel onglet avec rel="nofollow noopener noreferrer ugc" ;
 *  - seules les images envoyées sur le site (/uploads/forum/…) sont affichées : une image externe devient
 *    un simple lien (aucune requête vers un serveur tiers à l'affichage, donc pas de pistage des lecteurs).
 *
 * Les sauts de ligne simples sont conservés (<br>) : les messages écrits avant le Markdown s'affichent comme avant.
 */
final class ForumMarkdown
{
    /** Préfixe des images autorisées dans les messages. */
    public const IMAGE_PATH_PREFIX = '/uploads/forum/';

    /** Nombre maximal de membres notifiés pour des mentions dans un même message. */
    public const MAX_NOTIFIED_MENTIONS = 10;

    private ?MarkdownConverter $converter = null;

    public function __construct(private readonly MentionResolver $mentionResolver)
    {
    }

    public function toHtml(string $markdown): string
    {
        // One query for every member mentioned in the text, instead of one per mention
        $this->mentionResolver->preload(self::extractMentions($markdown));

        return $this->converter()->convert($markdown)->getContent();
    }

    /** Texte brut court (aperçus : accueil, fil d'actualité) : Markdown rendu puis balises retirées. */
    public function toExcerpt(string $markdown, int $length = 160): string
    {
        $text = html_entity_decode(strip_tags($this->converter()->convert($markdown)->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }

    /**
     * Pseudos mentionnés dans un texte (sans le « @ »), dans l'ordre d'apparition, sans doublon (insensible à la casse).
     * Les blocs de code et le code en ligne sont ignorés.
     *
     * @return string[]
     */
    public static function extractMentions(string $markdown): array
    {
        return MentionResolver::extract((string) preg_replace(['/```.*?```/s', '/`[^`\n]*`/'], ' ', $markdown));
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter !== null) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br>\n"],
            'external_link' => [
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
                'html_class' => 'link',
            ],
            'mentions' => [
                'user' => [
                    'prefix' => '@',
                    'pattern' => MentionResolver::PATTERN,
                    'generator' => new class($this) implements MentionGeneratorInterface {
                        public function __construct(private readonly ForumMarkdown $markdown)
                        {
                        }

                        public function generateMention(Mention $mention): ?AbstractInline
                        {
                            return $this->markdown->mentionLink($mention);
                        }
                    },
                ],
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new ExternalLinkExtension());
        $environment->addExtension(new MentionExtension());
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'restrictImages']);

        return $this->converter = new MarkdownConverter($environment);
    }

    /** @internal Générateur des mentions : lien vers le profil si le membre existe, texte brut sinon. */
    public function mentionLink(Mention $mention): ?AbstractInline
    {
        $username = $this->mentionResolver->resolve($mention->getIdentifier());
        if ($username === null) {
            return null;
        }

        $mention->setUrl($this->mentionResolver->profileUrl($username));
        $mention->setLabel('@' . $username);
        $mention->data->append('attributes/class', 'mention');

        return $mention;
    }

    /** @internal Remplace les images non hébergées sur le site par un lien vers l'image. */
    public function restrictImages(DocumentParsedEvent $event): void
    {
        // Collecte d'abord : on ne remplace pas de nœud pendant le parcours de l'arbre
        $images = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Image) {
                $images[] = $node;
            }
        }

        foreach ($images as $node) {
            $url = $node->getUrl();
            if (self::isLocalImage($url)) {
                $node->data->set('attributes/loading', 'lazy');
                $node->data->set('attributes/decoding', 'async');
                $node->data->append('attributes/class', 'post-image');
                continue;
            }

            $alt = '';
            foreach ($node->iterator() as $child) {
                if ($child instanceof Text) {
                    $alt .= $child->getLiteral();
                }
            }
            $link = new Link($url, null, $node->getTitle());
            $link->appendChild(new Text('🖼 ' . ($alt !== '' ? $alt : 'image externe')));
            $node->replaceWith($link);
        }
    }

    public static function isLocalImage(string $url): bool
    {
        return (bool) preg_match('#^' . preg_quote(self::IMAGE_PATH_PREFIX, '#') . '[a-z0-9-]+\.webp$#D', $url);
    }
}
