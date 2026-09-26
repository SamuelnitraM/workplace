<?php

namespace App\Text;

use App\Repository\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @pseudo mentions shared by every text of the site (forum posts, private messages, group chat):
 * extraction, resolution of the mentioned members (one query per text, cached for the request)
 * and rendering of plain text with the mentions of existing members turned into links to their profile.
 */
final class MentionResolver
{
    /** Mentionable username (same characters as the username constraint; no trailing "." or "-", for "@pseudo."). */
    public const PATTERN = '[A-Za-z0-9_](?:[A-Za-z0-9_.-]{0,48}[A-Za-z0-9_])?';

    private const MENTION_REGEX = '/(?<![\w@])@(' . self::PATTERN . ')/u';

    /** @var array<string, string|false> lowercase username => actual username (false: unknown member) */
    private array $knownUsers = [];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Usernames mentioned in a text (without the "@"), in order of appearance, without duplicates (case-insensitive).
     *
     * @return string[]
     */
    public static function extract(string $text): array
    {
        preg_match_all(self::MENTION_REGEX, $text, $matches);
        $mentions = [];
        foreach ($matches[1] as $username) {
            $mentions[mb_strtolower($username)] ??= $username;
        }
        return array_values($mentions);
    }

    /**
     * Loads the members of several usernames in a single query.
     *
     * @param string[] $usernames
     */
    public function preload(array $usernames): void
    {
        $missing = array_values(array_filter($usernames, fn (string $name): bool => !isset($this->knownUsers[mb_strtolower($name)])));
        if ($missing === []) {
            return;
        }
        foreach ($missing as $name) {
            $this->knownUsers[mb_strtolower($name)] = false;
        }
        foreach ($this->userRepository->findByUsernames($missing) as $user) {
            $this->knownUsers[mb_strtolower((string) $user->getUsername())] = (string) $user->getUsername();
        }
    }

    /** Actual username of a mentioned member, or null when nobody has this username. */
    public function resolve(string $identifier): ?string
    {
        $this->preload([$identifier]);
        return $this->knownUsers[mb_strtolower($identifier)] ?: null;
    }

    public function profileUrl(string $username): string
    {
        return $this->urlGenerator->generate('app_profil_show', ['username' => $username]);
    }

    /**
     * Plain text as safe HTML: everything is escaped, and the mentions of existing members become links
     * to their profile (<a class="mention">). Line breaks are kept as they are (display with white-space: pre-line).
     */
    public function linkify(string $text): string
    {
        $this->preload(self::extract($text));
        $parts = preg_split(self::MENTION_REGEX, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $html .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
                continue;
            }
            $username = $this->resolve($part);
            $html .= $username === null
                ? htmlspecialchars('@' . $part, ENT_QUOTES, 'UTF-8')
                : sprintf('<a href="%s" class="mention">@%s</a>', htmlspecialchars($this->profileUrl($username), ENT_QUOTES, 'UTF-8'), htmlspecialchars($username, ENT_QUOTES, 'UTF-8'));
        }
        return $html;
    }
}
