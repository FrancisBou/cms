<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\tools;

use Craft;
use craft\app\db\Query;

/**
 * Asset Index tool.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class AssetIndex extends BaseTool
{
	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc ComponentTypeInterface::getName()
	 *
	 * @return string
	 */
	public function getName()
	{
		return Craft::t('app', 'Update Asset Indexes');
	}

	/**
	 * @inheritDoc ToolInterface::getIconValue()
	 *
	 * @return string
	 */
	public function getIconValue()
	{
		return 'assets';
	}

	/**
	 * @inheritDoc ToolInterface::getOptionsHtml()
	 *
	 * @return string
	 */
	public function getOptionsHtml()
	{
		$sources = Craft::$app->assetSources->getAllSources();
		$sourceOptions = [];

		foreach ($sources as $source)
		{
			$sourceOptions[] = [
				'label' => $source->name,
				'value' => $source->id
			];
		}

		return Craft::$app->templates->render('_includes/forms/checkboxSelect', [
			'name'    => 'sources',
			'options' => $sourceOptions
		]);
	}

	/**
	 * @inheritDoc ToolInterface::performAction()
	 *
	 * @param array $params
	 *
	 * @return array|null
	 */
	public function performAction($params = [])
	{
		// Initial request
		if (!empty($params['start']))
		{
			$batches = [];
			$sessionId = Craft::$app->assetIndexing->getIndexingSessionId();

			// Selection of sources or all sources?
			if (is_array($params['sources']))
			{
				$sourceIds = $params['sources'];
			}
			else
			{
				$sourceIds = Craft::$app->assetSources->getViewableSourceIds();
			}

			$missingFolders = [];

			$grandTotal = 0;

			foreach ($sourceIds as $sourceId)
			{
				// Get the indexing list
				$indexList = Craft::$app->assetIndexing->getIndexListForSource($sessionId, $sourceId);

				if (!empty($indexList['error']))
				{
					return $indexList;
				}

				if (isset($indexList['missingFolders']))
				{
					$missingFolders += $indexList['missingFolders'];
				}

				$batch = [];

				for ($i = 0; $i < $indexList['total']; $i++)
				{
					$batch[] = [
									'params' => [
										'sessionId' => $sessionId,
										'sourceId' => $sourceId,
										'total' => $indexList['total'],
										'offset' => $i,
										'process' => 1
									]
					];
				}

				$batches[] = $batch;
			}

			Craft::$app->getSession()->add('assetsSourcesBeingIndexed', $sourceIds);
			Craft::$app->getSession()->add('assetsMissingFolders', $missingFolders);
			Craft::$app->getSession()->add('assetsTotalSourcesToIndex', count($sourceIds));
			Craft::$app->getSession()->add('assetsTotalSourcesIndexed', 0);

			return [
				'batches' => $batches,
				'total'   => $grandTotal
			];
		}
		else if (!empty($params['process']))
		{
			// Index the file
			Craft::$app->assetIndexing->processIndexForSource($params['sessionId'], $params['offset'], $params['sourceId']);

			// More files to index.
			if (++$params['offset'] < $params['total'])
			{
				return [
					'success' => true
				];
			}
			else
			{
				// Increment the amount of sources indexed
				Craft::$app->getSession()->add('assetsTotalSourcesIndexed', Craft::$app->getSession()->get('assetsTotalSourcesIndexed', 0) + 1);

				// Is this the last source to finish up?
				if (Craft::$app->getSession()->get('assetsTotalSourcesToIndex', 0) <= Craft::$app->getSession()->get('assetsTotalSourcesIndexed', 0))
				{
					$sourceIds = Craft::$app->getSession()->get('assetsSourcesBeingIndexed', []);
					$missingFiles = Craft::$app->assetIndexing->getMissingFiles($sourceIds, $params['sessionId']);
					$missingFolders = Craft::$app->getSession()->get('assetsMissingFolders', []);

					$responseArray = [];

					if (!empty($missingFiles) || !empty($missingFolders))
					{
						$responseArray['confirm'] = Craft::$app->templates->render('assets/_missing_items', ['missingFiles' => $missingFiles, 'missingFolders' => $missingFolders]);
						$responseArray['params'] = ['finish' => 1];
					}
					// Clean up stale indexing data (all sessions that have all recordIds set)
					$sessionsInProgress = (new Query())
											->select('sessionId')
											->from('assetindexdata')
											->where('recordId IS NULL')
											->groupBy('sessionId')
											->scalar();

					if (empty($sessionsInProgress))
					{
						Craft::$app->getDb()->createCommand()->delete('assetindexdata')->execute();
					}
					else
					{
						Craft::$app->getDb()->createCommand()->delete('assetindexdata', ['not in', 'sessionId', $sessionsInProgress])->execute();
					}

					// Generate the HTML for missing files and folders
					return [
						'batches' => [
							[
								$responseArray
							]
						]
					];
				}
			}
		}
		else if (!empty($params['finish']))
		{
			if (!empty($params['deleteFile']) && is_array($params['deleteFile']))
			{
				Craft::$app->assetIndexing->removeObsoleteFileRecords($params['deleteFile']);
			}

			if (!empty($params['deleteFolder']) && is_array($params['deleteFolder']))
			{
				Craft::$app->assetIndexing->removeObsoleteFolderRecords($params['deleteFolder']);
			}

			return [
				'finished' => 1
			];
		}

		return [];
	}
}
