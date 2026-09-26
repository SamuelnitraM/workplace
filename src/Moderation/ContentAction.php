<?php

namespace App\Moderation;

/** What happens to the reported content in a moderation decision. */
enum ContentAction: string
{
    case Keep = 'keep';
    case Hide = 'hide';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Keep => 'Laisser le contenu en ligne',
            self::Hide => 'Masquer le contenu',
            self::Delete => 'Supprimer le contenu',
        };
    }

    public function isAllowedFor(ReportTargetType $targetType): bool
    {
        return match ($this) {
            self::Keep => true,
            self::Hide => $targetType->canBeHidden(),
            self::Delete => $targetType->canBeDeleted(),
        };
    }

    /** Resolution recorded for this action, NULL when the content stays online. */
    public function resolution(): ?ReportResolution
    {
        return match ($this) {
            self::Keep => null,
            self::Hide => ReportResolution::Hidden,
            self::Delete => ReportResolution::Deleted,
        };
    }
}
