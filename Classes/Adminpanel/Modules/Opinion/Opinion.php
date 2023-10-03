<?php

declare(strict_types=1);

namespace Supseven\Opinion\Adminpanel\Modules\Opinion;

use Psr\Http\Message\ServerRequestInterface;
use Supseven\Opinion\Service\OpinionService;
use TYPO3\CMS\Adminpanel\ModuleApi\AbstractSubModule;
use TYPO3\CMS\Adminpanel\ModuleApi\DataProviderInterface;
use TYPO3\CMS\Adminpanel\ModuleApi\ModuleData;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class Opinion extends AbstractSubModule implements DataProviderInterface
{
    public function getDataToStore(ServerRequestInterface $request): ModuleData
    {
        $tsfe = $this->getTypoScriptFrontendController();

        return new ModuleData([
            'info' => [
                'beUser'         => OpinionService::getBeUserName(),
                'pageUid'        => $tsfe->id,
                'pageType'       => $tsfe->getPageArguments()->getPageType(),
                'noCache'        => $this->isNoCacheEnabled(),
                'countUserInt'   => count($tsfe->config['INTincScript'] ?? []),
                'totalParsetime' => $this->getTimeTracker()->getParseTime(),
                'imagesOnPage'   => $this->collectImagesOnPage(),
                'documentSize'   => $this->collectDocumentSize(),
            ],
        ]);
    }

    /**
     * Creates the content for the "info" section ("module") of the Admin Panel
     *
     * @param ModuleData $data
     * @return string HTML content for the section. Consists of a string with table-rows with four columns.
     * @see display()
     */
    public function getContent(ModuleData $data): string
    {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $xhrUrl = $uriBuilder->buildUriFromRoute('ajax_opinion-backend', [], UriBuilder::ABSOLUTE_URL);

        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $templateNameAndPath = 'EXT:opinion/Resources/Private/Templates/Modules/Opinion/Opinion.html';
        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName($templateNameAndPath));
        $view->assignMultiple($data->getArrayCopy());
        $view->assign('xhrUrl', $xhrUrl);

        return $view->render();
    }

    /**
     * Identifier for this Sub-module,
     * for example "preview" or "cache"
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return 'opinion_general';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return $this->getLanguageService()->sL(
            'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:sub.general.label'
        );
    }

    /**
     * Collects images from TypoScriptFrontendController and calculates the total size.
     * Returns human readable image sizes for fluid template output
     *
     * @return array
     */
    protected function collectImagesOnPage(): array
    {
        $imagesOnPage = [
            'files' => [],
        ];

        $count = 0;
        $totalImageSize = 0;

        foreach (GeneralUtility::makeInstance(AssetCollector::class)->getMedia() as $file => $information) {
            $fileSize = (int)@filesize(Environment::getProjectPath() . $file);
            $imagesOnPage['files'][] = [
                'name'      => $file,
                'size'      => $fileSize,
                'sizeHuman' => GeneralUtility::formatSize($fileSize),
            ];
            $totalImageSize += $fileSize;
            $count++;
        }

        $imagesOnPage['totalSize'] = $totalImageSize;
        $imagesOnPage['totalSizeHuman'] = GeneralUtility::formatSize($totalImageSize);
        $imagesOnPage['total'] = $count;

        return $imagesOnPage;
    }

    /**
     * Gets the document size from the current page in a human readable format
     *
     * @return string
     */
    protected function collectDocumentSize(): string
    {
        $documentSize = strlen($this->getTypoScriptFrontendController()->content);

        return GeneralUtility::formatSize($documentSize);
    }

    /**
     * @return bool
     */
    protected function isNoCacheEnabled(): bool
    {
        return (bool)$this->getTypoScriptFrontendController()->no_cache;
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected function getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'];
    }

    /**
     * @return TimeTracker
     */
    protected function getTimeTracker(): TimeTracker
    {
        return GeneralUtility::makeInstance(TimeTracker::class);
    }
}
