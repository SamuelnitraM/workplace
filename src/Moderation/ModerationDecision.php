<?php

namespace App\Moderation;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * Set of actions prepared on a report page and applied together: fate of the content,
 * warning sent to the author, suspension of the author and internal note.
 * A decision without any action dismisses the report.
 */
final readonly class ModerationDecision
{
    public function __construct(
        public ContentAction $contentAction = ContentAction::Keep,
        public ?string $warning = null,
        public ?SuspensionDuration $suspension = null,
        public ?string $suspensionReason = null,
        public ?string $note = null,
    ) {
    }

    /** Reads the fields of the report decision form: content, warning, duration, suspension_reason, note. */
    public static function fromForm(InputBag $form): self
    {
        return new self(
            ContentAction::tryFrom($form->getString('content')) ?? ContentAction::Keep,
            self::cleanText($form->getString('warning')),
            SuspensionDuration::tryFrom($form->getString('duration')),
            self::cleanText($form->getString('suspension_reason')),
            self::cleanText($form->getString('note')),
        );
    }

    public function isDismissal(): bool
    {
        return $this->contentAction === ContentAction::Keep && $this->warning === null && $this->suspension === null;
    }

    public function concernsAuthor(): bool
    {
        return $this->warning !== null || $this->suspension !== null;
    }

    /**
     * Resolutions recorded on the report, most severe first.
     *
     * @return list<ReportResolution>
     */
    public function resolutions(): array
    {
        if ($this->isDismissal()) {
            return [ReportResolution::Dismissed];
        }
        $resolutions = array_filter([
            $this->suspension !== null ? ReportResolution::forSuspension($this->suspension) : null,
            $this->contentAction->resolution(),
            $this->warning !== null ? ReportResolution::Warned : null,
        ]);
        return ReportResolution::sortBySeverity($resolutions);
    }

    /** Readable summary kept on the report for the moderation history. */
    public function summary(): ?string
    {
        $lines = array_filter([
            $this->warning !== null ? 'Avertissement : ' . $this->warning : null,
            $this->suspension !== null ? sprintf('Suspension (%s) : %s', mb_strtolower($this->suspension->label()), $this->suspensionReason) : null,
            $this->note !== null ? 'Note : ' . $this->note : null,
        ]);
        return $lines === [] ? null : implode("\n", $lines);
    }

    private static function cleanText(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
