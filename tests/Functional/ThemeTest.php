<?php

namespace App\Tests\Functional;

use Symfony\Component\BrowserKit\Cookie;

class ThemeTest extends FunctionalTestCase
{
    public function testChosenThemeIsRenderedByTheServer(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertNull($crawler->filter('html')->attr('data-theme'));
        self::assertCount(3, $crawler->filter('footer .theme-switch input[type=radio]'));
        self::assertSame('auto', $crawler->filter('footer .theme-switch input:checked')->attr('value'));
        $this->client->getCookieJar()->set(new Cookie('hf_theme', 'light'));
        $crawler = $this->client->request('GET', '/login');
        self::assertSame('light', $crawler->filter('html')->attr('data-theme'));
        self::assertSame('light', $crawler->filter('footer .theme-switch input:checked')->attr('value'));
        self::assertSame(['#f3f4f7', '#f3f4f7'], $crawler->filter('meta[name=theme-color]')->each(static fn ($meta) => $meta->attr('content')));
        $this->client->getCookieJar()->set(new Cookie('hf_theme', 'rainbow'));
        $crawler = $this->client->request('GET', '/login');
        self::assertNull($crawler->filter('html')->attr('data-theme'));
    }
}
