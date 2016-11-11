<?php

namespace Shel\Crawler\Command;

/*                                                                        *
 * This script belongs to the Flow plugin Shel.Crawler                    *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\Neos\Controller\Frontend\NodeController;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\Workspace;
use TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository;

/**
 *
 * @Flow\Scope("singleton")
 */
class CrawlerCommandController extends CommandController
{
    /**
     * @Flow\Inject
     *
     * @var \TYPO3\Flow\Log\SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     *
     * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     *
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @param string $site
     */
    public function crawlNodesCommand($site = '')
    {
        /** @var Workspace $liveWorkspace */
        $liveWorkspace = $this->workspaceRepository->findByName('live')->getFirst();

        $rootNode = $liveWorkspace->getRootNodeData();

        $nodes = $this->nodeDataRepository->findByParentAndNodeTypeRecursively(
            $rootNode->getPath(),
            'TYPO3.Neos:Document',
            $liveWorkspace
        );

        $nodeController = $this->objectManager->get(NodeController::class);

        /** @var NodeInterface $node */
        foreach ($nodes as $node) {
            $result = $nodeController->showAction($node);

            print_r($result);
            return;
        }
    }
}
