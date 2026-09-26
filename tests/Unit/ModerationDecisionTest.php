<?php

namespace App\Tests\Unit;

use App\Moderation\ContentAction;
use App\Moderation\ModerationDecision;
use App\Moderation\ReportResolution;
use App\Moderation\SuspensionDuration;
use App\Statistics\BarChart;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class ModerationDecisionTest extends TestCase
{
    public function testEmptyDecisionDismisses(): void
    {
        $decision = ModerationDecision::fromForm(new InputBag(['content' => 'keep', 'warning' => '  ', 'duration' => '']));
        self::assertTrue($decision->isDismissal());
        self::assertSame([ReportResolution::Dismissed], $decision->resolutions());
        self::assertNull($decision->summary());
    }

    public function testCombinedDecisionListsResolutionsMostSevereFirst(): void
    {
        $decision = new ModerationDecision(ContentAction::Hide, 'Reste courtois', SuspensionDuration::Permanent, 'Récidive', 'Vu en réunion');
        self::assertSame([ReportResolution::Banned, ReportResolution::Hidden, ReportResolution::Warned], $decision->resolutions());
        self::assertSame("Avertissement : Reste courtois\nSuspension (définitive) : Récidive\nNote : Vu en réunion", $decision->summary());
    }

    public function testChartMaximumIsARoundEvenNumber(): void
    {
        self::assertSame([2.0, 2.0, 4.0, 6.0, 10.0, 20.0, 40.0], array_map(BarChart::niceMaximum(...), [0, 2, 3, 5, 10, 12, 37]));
        $chart = new BarChart('chart', 'Titre', 'Description', [['label' => '01/09', 'value' => 3.0, 'detail' => '3']]);
        self::assertSame([0.0, 2.0, 4.0], $chart->ticks);
        self::assertSame(75.0, $chart->heightOf(3.0));
    }
}
