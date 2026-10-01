<?php declare(strict_types=1);

use Base3\Api\IAssetResolver;
use Base3\Settings\Api\ISettingsStore;
use Base3Ilias\Base3\Base3IliasFileManagerHttpService;
use Base3Ilias\Base3\Base3IliasFileStorage;
use Base3Ilias\Base3\Base3IliasManagedFileStorageService;
use Base3Ilias\Base3\Base3IliasRuntime;
use ILIAS\DI\Container;

/**
 * @ilCtrl_isCalledBy ilObjBase3FileManagerRepositoryObjectGUI: ilRepositoryGUI, ilAdministrationGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjBase3FileManagerRepositoryObjectGUI: ilPermissionGUI, ilInfoScreenGUI, ilCommonActionDispatcherGUI
 */
class ilObjBase3FileManagerRepositoryObjectGUI extends ilObjectPluginGUI {

	private const OWNER_GROUP = 'repo-filemanager';
	private const NOTE_GROUP = 'repo-filemanager';

	public function getType(): string {
		return ilBase3FileManagerRepositoryObjectPlugin::ID;
	}

	public function getAfterCreationCmd(): string {
		return 'editFiles';
	}

	public function getStandardCmd(): string {
		return 'showContent';
	}

	public function performCommand(string $cmd): void {
		switch ($cmd) {
			case 'showContent':
				$this->checkPermission('read');
				$this->showContent();
				break;

			case 'edit':
				$this->checkPermission('write');
				$this->edit();
				break;

			case 'update':
				$this->checkPermission('write');
				$this->update();
				break;

			case 'editFiles':
				$this->checkPermission('write');
				$this->editFiles();
				break;

			case 'saveFiles':
				$this->checkPermission('write');
				$this->saveFiles();
				break;

			case 'fileManager':
				$this->checkPermission($this->isDownloadAction() ? 'read' : 'write');
				$this->fileManager();
				break;
		}
	}

	protected function setTabs(): void {
		if ($this->checkPermissionBool('read')) {
			$this->tabs->addTab(
				'files',
				$this->txt('files'),
				$this->ctrl->getLinkTarget($this, 'showContent')
			);
		}

		if ($this->checkPermissionBool('write')) {
			$this->tabs->addTab(
				'settings',
				$this->lng->txt('settings'),
				$this->ctrl->getLinkTarget($this, 'edit')
			);

			$this->tabs->addTab(
				'file_management',
				$this->txt('file_management'),
				$this->ctrl->getLinkTarget($this, 'editFiles')
			);
		}

		$this->addInfoTab();
		$this->addPermissionTab();
	}

	protected function showContent(): void {
		$this->tabs->activateTab('files');
		$content = '<div class="xb3f-content">';
		$note = trim($this->getNote());

		if ($note !== '') {
			$content .= '<p>' . $this->escape($note) . '</p>';
		}

		$content .= $this->renderDirectoryTree('');
		$content .= '</div>';
		$this->tpl->setContent($content);
	}

	protected function editFiles(): void {
		$this->tabs->activateTab('file_management');
		$objectId = $this->objectId();
		$containerId = $this->managedStorageService()->getOrCreateStorageIdentifier(
			self::OWNER_GROUP,
			$this->ownerName(),
			Base3IliasFileStorage::MODE_CONTAINER
		);
		$assetResolver = $this->assetResolver();
		$cssUrl = $assetResolver->resolve('plugin/ClientStack/assets/filemanager/styles/filemanager.css');
		$moduleUrl = $assetResolver->resolve('plugin/ClientStack/assets/filemanager/index.js');
		$this->getDic()->ui()->mainTemplate()->addCss($cssUrl);

		$formId = 'xb3f_form_' . $objectId;
		$managerId = 'xb3f_manager_' . $objectId;
		$config = [
			'formId' => $formId,
			'managerId' => $managerId,
			'containerId' => $containerId,
			'moduleUrl' => $moduleUrl,
			'maxFileSize' => Base3IliasFileManagerHttpService::DEFAULT_MAX_FILE_SIZE,
			'endpoints' => $this->fileManagerEndpoints(),
			'strings' => [
				'dropFiles' => $this->txt('drop_files'),
				'selectFiles' => $this->txt('select_files'),
				'newFolder' => $this->txt('new_folder'),
				'refresh' => $this->txt('refresh'),
				'rename' => $this->txt('rename'),
				'copy' => $this->txt('copy_file'),
				'move' => $this->txt('move_file'),
				'delete' => $this->txt('delete_file'),
				'download' => $this->txt('download'),
			],
		];
		$configJson = json_encode(
			$config,
			JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);

		$html = '<form id="' . $this->escape($formId) . '" method="post" action="'
			. $this->escape($this->ctrl->getFormAction($this)) . '">';
		$html .= '<div class="form-group">';
		$html .= '<label for="xb3f_note_' . $objectId . '">' . $this->escape($this->txt('note')) . '</label>';
		$html .= '<textarea class="form-control" id="xb3f_note_' . $objectId . '" name="note" rows="3">'
			. $this->escape($this->getNote()) . '</textarea>';
		$html .= '<p class="help-block">' . $this->escape($this->txt('note_info')) . '</p>';
		$html .= '</div>';
		$html .= '<div id="' . $this->escape($managerId) . '"></div>';
		$html .= '<div style="margin-top: 1rem;">';
		$html .= '<button type="submit" class="btn btn-default" name="cmd" value="saveFiles">'
			. $this->escape($this->lng->txt('save')) . '</button>';
		$html .= '</div>';
		$html .= '</form>';
		$html .= '<script>(async function(){'
			. 'const config=' . $configJson . ';'
			. 'const module=await import(config.moduleUrl);'
			. 'const endpoints={};'
			. 'for(const [name,url] of Object.entries(config.endpoints)){endpoints[name]=new URL(url,window.location.href).toString();}'
			. 'const adapter=new module.HttpFileManagerAdapter({baseUrl:window.location.origin,endpoints});'
			. 'const manager=new module.FileManager(document.getElementById(config.managerId),{'
			. 'mode:"container",adapter,containerId:config.containerId,strings:config.strings,'
			. 'form:{form:document.getElementById(config.formId),name:"file_container",waitForUploads:true},'
			. 'upload:{maxFileSize:config.maxFileSize,chunkSize:8*1024*1024,maxParallelFiles:3,maxParallelChunks:2,retryLimit:3}'
			. '});'
			. 'manager.init();'
			. 'await manager.ready;'
			. 'window["xb3f_"+config.containerId]=manager;'
			. '})().catch(function(error){console.error(error);});</script>';

		$this->tpl->setContent($html);
	}

	protected function saveFiles(): void {
		$body = $this->parsedPostBody();
		$submittedContainer = trim((string)($body['file_container'] ?? ''));
		$expectedContainer = $this->managedStorageService()->getOrCreateStorageIdentifier(
			self::OWNER_GROUP,
			$this->ownerName(),
			Base3IliasFileStorage::MODE_CONTAINER
		);

		if ($submittedContainer === '' || !hash_equals($expectedContainer, $submittedContainer)) {
			throw new RuntimeException('Submitted FileManager container does not match this repository object.');
		}

		$note = trim((string)($body['note'] ?? ''));
		if (mb_strlen($note) > 2000) {
			$note = mb_substr($note, 0, 2000);
		}
		$this->saveNote($note);

		$this->tpl->setOnScreenMessage('success', $this->txt('files_saved'), true);
		$this->ctrl->redirect($this, 'editFiles');
	}

	protected function fileManager(): void {
		$query = $this->getDic()->http()->request()->getQueryParams();
		$action = trim((string)($query['fm_action'] ?? ''));
		$this->fileManagerHttpService()->handle(
			$action,
			self::OWNER_GROUP,
			$this->ownerName(),
			Base3IliasFileStorage::MODE_CONTAINER,
			(int)$this->getDic()->user()->getId(),
			Base3IliasFileManagerHttpService::DEFAULT_MAX_FILE_SIZE
		);
	}

	protected function renderDirectoryTree(string $path): string {
		$items = $this->managedStorageService()->listDescriptors(
			self::OWNER_GROUP,
			$this->ownerName(),
			Base3IliasFileStorage::MODE_CONTAINER,
			$path
		);
		if ($items === []) {
			return $path === ''
				? '<p>' . $this->escape($this->txt('no_files')) . '</p>'
				: '<ul></ul>';
		}

		$html = '<ul class="xb3f-tree">';
		foreach ($items as $item) {
			$html .= '<li>';
			if ($item['kind'] === 'directory') {
				$html .= '<strong>' . $this->escape($item['name']) . '</strong>';
				$html .= $this->renderDirectoryTree($item['path']);
			}
			else {
				$url = $this->appendQueryParameter($this->fileManagerEndpoints()['download'], 'path', $item['path']);
				$html .= '<a href="' . $this->escape($url) . '">' . $this->escape($item['name']) . '</a>';
				if ($item['size'] !== null) {
					$html .= ' <small>(' . $this->escape($this->formatBytes((int)$item['size'])) . ')</small>';
				}
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	protected function fileManagerEndpoints(): array {
		return Base3IliasFileManagerHttpService::buildEndpoints(
			$this->ctrl->getLinkTarget($this, 'fileManager')
		);
	}

	protected function isDownloadAction(): bool {
		$query = $this->getDic()->http()->request()->getQueryParams();
		return trim((string)($query['fm_action'] ?? '')) === 'download';
	}

	protected function parsedPostBody(): array {
		$body = $this->getDic()->http()->request()->getParsedBody();
		if ($body === null) {
			return [];
		}
		if (!is_array($body)) {
			throw new InvalidArgumentException('POST request body must be an array.');
		}
		return $body;
	}

	protected function managedStorageService(): Base3IliasManagedFileStorageService {
		Base3IliasRuntime::bootOnce(false, true);
		return Base3IliasRuntime::getServiceLocator()->get(Base3IliasManagedFileStorageService::class);
	}

	protected function fileManagerHttpService(): Base3IliasFileManagerHttpService {
		Base3IliasRuntime::bootOnce(false, true);
		return Base3IliasRuntime::getServiceLocator()->get(Base3IliasFileManagerHttpService::class);
	}

	protected function settingsStore(): ISettingsStore {
		Base3IliasRuntime::bootOnce(false, true);
		return Base3IliasRuntime::getServiceLocator()->get(ISettingsStore::class);
	}

	protected function getNote(): string {
		$settings = $this->settingsStore()->get(self::NOTE_GROUP, $this->ownerName(), []);
		return (string)($settings['note'] ?? '');
	}

	protected function saveNote(string $note): void {
		$settings = $this->settingsStore()->get(self::NOTE_GROUP, $this->ownerName(), []);
		unset($settings['storage_rid']);
		$settings['note'] = $note;
		$this->settingsStore()->set(self::NOTE_GROUP, $this->ownerName(), $settings);
		$this->settingsStore()->save();
	}

	protected function assetResolver(): IAssetResolver {
		Base3IliasRuntime::bootOnce(false, true);
		return Base3IliasRuntime::getServiceLocator()->get(IAssetResolver::class);
	}

	protected function ownerName(): string {
		return 'obj_' . $this->objectId();
	}

	protected function objectId(): int {
		return (int)$this->object->getId();
	}

	protected function appendQueryParameter(string $url, string $name, string $value): string {
		$separator = str_contains($url, '?') ? '&' : '?';
		return $url . $separator . rawurlencode($name) . '=' . rawurlencode($value);
	}

	protected function formatBytes(int $bytes): string {
		$units = ['B', 'KB', 'MB', 'GB'];
		$value = max(0, $bytes);
		$unit = 0;
		while ($value >= 1024 && $unit < count($units) - 1) {
			$value /= 1024;
			$unit++;
		}
		return ($unit === 0 ? (string)(int)$value : number_format($value, 1)) . ' ' . $units[$unit];
	}

	protected function escape(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	protected function getDic(): Container {
		return $GLOBALS['DIC'];
	}
}
