<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Seo\LlmsTxt;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class LlmsTxtTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testArrayBecomesTheLlmstxtFormatWithAbsoluteLinks(): void
    {
        define('BASE_URI', '/site');
        Config::set('app.url.base', 'https://example.com');
        Config::set('app.llms', [
            'title' => 'Acme',
            'summary' => "Tools for\nbuilders.",
            'details' => 'Start with the guide.',
            'sections' => [
                'Docs' => [
                    ['url' => '/guide/', 'title' => 'Guide', 'notes' => 'Install and run'],
                    ['url' => 'https://github.com/acme/tools'],
                ],
                'Optional' => [['url' => '/blog', 'title' => 'Blog']],
            ],
        ]);

        $this->assertSame(
            "# Acme\n\n> Tools for\n> builders.\n\nStart with the guide.\n"
            . "\n## Docs\n\n- [Guide](https://example.com/site/guide): Install and run\n"
            . "- [https://github.com/acme/tools](https://github.com/acme/tools)\n"
            . "\n## Optional\n\n- [Blog](https://example.com/site/blog)\n",
            LlmsTxt::text(),
        );
    }

    public function testTitleDefaultsToTheSiteName(): void
    {
        Config::set('app.seo.site_name', 'Acme');
        Config::set('app.llms', []);

        $this->assertTrue(LlmsTxt::enabled());
        $this->assertSame("# Acme\n", LlmsTxt::text());
    }
}
