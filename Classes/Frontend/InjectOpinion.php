<?php

declare(strict_types=1);

namespace Supseven\Opinion\Frontend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class InjectOpinion implements MiddlewareInterface
{
    /** @var PageRenderer */
    private $pageRenderer;

    public function __construct()
    {
        $this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // should be replaced by a user setting, if the user can give opinions
        // i guess i dont need it since it is added in the opinion admin panel module
//        if ($GLOBALS['BE_USER']->isAdmin()) {
//            $this->pageRenderer->addJsFooterFile('EXT:opinion/Resources/Public/JavaScript/Opinion.js');
//        }

        return $handler->handle($request);
    }
}
