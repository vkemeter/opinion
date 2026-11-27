<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\ViewHelpers;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;

final class LabelViewHelperTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'opinion' => ['categories' => 'bug,question=<b>Question</b>', 'statuses' => 'open,waiting,done', 'closedStatuses' => 'done'],
        ],
    ];

    /**
     * Requirement: Category labels (output escaped)
     */
    #[Test]
    public function rendersTheLabelEscaped(): void
    {
        self::assertSame(
            'Bug|&lt;b&gt;Question&lt;/b&gt;|feature',
            $this->render("{opinion:label(list: 'category', value: 'bug')}|{opinion:label(list: 'category', value: 'question')}|{opinion:label(list: 'category', value: 'feature')}"),
        );
    }

    /**
     * Requirement: Status labels and badges
     */
    #[Test]
    public function statusBadgeFollowsTheRoleOfTheStatus(): void
    {
        self::assertSame(
            'info|warning|success|warning',
            $this->render("{opinion:statusBadge(value: 'open')}|{opinion:statusBadge(value: 'waiting')}|{opinion:statusBadge(value: 'done')}|{opinion:statusBadge(value: 'removed')}"),
        );
    }

    private function render(string $source): string
    {
        $request = new ServerRequest('https://example.com/typo3/')->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('{namespace opinion=Vkemeter\Opinion\ViewHelpers}' . $source);

        return (string)new TemplateView($context)->render();
    }
}
