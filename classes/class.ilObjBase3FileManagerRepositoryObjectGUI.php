<?php declare(strict_types=1);

use Base3\Api\IAssetResolver;
use Base3Ilias\Base3\Base3IliasRuntime;
use ILIAS\DI\Container;
use ResourceFoundation\Api\IFileStorage;

require_once __DIR__ . '/class.ilBase3FileManagerRepositoryObjectStorageService.php';
require_once __DIR__ . '/class.ilBase3FileManagerRepositoryObjectUploadService.php';

/**
 * @ilCtrl_isCalledBy ilObjBase3FileManagerRepositoryObjectGUI: ilRepositoryGUI, ilAdministrationGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjBase3FileManagerRepositoryObjectGUI: ilPermissionGUI, ilInfoScreenGUI, ilCommonActionDispatcherGUI
 */
class ilObjBase3FileManagerRepositoryObjectGUI extends ilObjectPluginGUI {

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

			case 'fmList':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->fileManagerList());
				break;

			case 'fmStat':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->fileManagerStat());
				break;

			case 'fmMkdir':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->fileManagerMkdir());
				break;

			case 'fmDelete':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->fileManagerDelete());
				break;

			case 'fmRmdir':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->fileManagerRmdir());
				break;

			case 'fmCopy':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->fileManagerCopy());
				break;

			case 'fmMove':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->fileManagerMove());
				break;

			case 'fmUploadStart':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->uploadService()->start($this->jsonBody()));
				break;

			case 'fmUploadChunk':
				$this->checkPermission('write');
				$this->respondJson(fn() => $this->uploadService()->saveChunk($this->parsedPostBody(), $_FILES));
				break;

			case 'fmUploadStatus':
				$this->checkPermission('write');
				$this->respondJson(function(): array {
					$body = $this->jsonBody();
					return $this->uploadService()->status((string) ($body['uploadId'] ?? ''));
				});
				break;

			case 'fmUploadFinish':
				$this->checkPermission('write');
				$this->respondJson(function(): array {
					$body = $this->jsonBody();
					return $this->uploadService()->finish((string) ($body['uploadId'] ?? ''));
				});
				break;

			case 'fmUploadAbort':
				$this->checkPermission('write');
				$this->respondJson(function(): array {
					$body = $this->jsonBody();
					return $this->uploadService()->abort((string) ($body['uploadId'] ?? ''));
				});
				break;

			case 'fmDownload':
				$this->checkPermission('read');
				$this->download();
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
		$service = $this->storageService();
		$note = trim($service->getNote($this->objectId()));
		$content = '<div class="xb3f-content">';

		if ($note !== '') {
			$content .= '<p>' . $this->escape($note) . '</p>';
		}

		$content .= $this->renderDirectoryTree($service, '');
		$content .= '</div>';
		$this->tpl->setContent($content);
	}

	protected function editFiles(): void {
		$this->tabs->activateTab('file_management');
		$service = $this->storageService();
		$objectId = $this->objectId();
		$rid = $service->getStorageIdentifier($objectId);
		$assetResolver = $this->assetResolver();
		$cssUrl = $assetResolver->resolve('plugin/ClientStack/assets/filemanager/styles/filemanager.css');
		$moduleUrl = $assetResolver->resolve('plugin/ClientStack/assets/filemanager/index.js');
		$this->getDic()->ui()->mainTemplate()->addCss($cssUrl);

		$formId = 'xb3f_form_' . $objectId;
		$managerId = 'xb3f_manager_' . $objectId;
		$config = [
			'formId' => $formId,
			'managerId' => $managerId,
			'containerId' => $rid,
			'moduleUrl' => $moduleUrl,
			'maxFileSize' => ilBase3FileManagerRepositoryObjectUploadService::MAX_FILE_SIZE,
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
			. $this->escape($service->getNote($objectId)) . '</textarea>';
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
		$service = $this->storageService();
		$objectId = $this->objectId();
		$submittedContainer = trim((string) ($_POST['file_container'] ?? ''));
		$expectedContainer = $service->getStorageIdentifier($objectId);

		if ($submittedContainer === '' || !hash_equals($expectedContainer, $submittedContainer)) {
			throw new RuntimeException('Submitted FileManager container does not match this repository object.');
		}

		$note = trim((string) ($_POST['note'] ?? ''));
		if (mb_strlen($note) > 2000) {
			$note = mb_substr($note, 0, 2000);
		}
		$service->saveNote($objectId, $note);

		$this->tpl->setOnScreenMessage('success', $this->txt('files_saved'), true);
		$this->ctrl->redirect($this, 'editFiles');
	}

	protected function fileManagerList(): array {
		$body = $this->jsonBody();
		$path = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($body['path'] ?? ''));
		$service = $this->storageService();

		return [
			'items' => $service->listDescriptors($this->objectId(), $path),
			'path' => $path,
			'containerId' => $service->getStorageIdentifier($this->objectId()),
		];
	}

	protected function fileManagerStat(): array {
		$body = $this->jsonBody();
		$path = (string) ($body['path'] ?? '');
		$descriptor = $this->storageService()->statDescriptor($this->objectId(), $path);

		if ($descriptor === null) {
			http_response_code(404);
			throw new RuntimeException('File or directory not found.');
		}

		return $descriptor;
	}

	protected function fileManagerMkdir(): array {
		$body = $this->jsonBody();
		$path = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($body['path'] ?? ''));

		if ($path === '' || !$this->storage()->mkdir($path)) {
			throw new RuntimeException('Directory could not be created.');
		}

		return ['success' => true];
	}

	protected function fileManagerDelete(): array {
		$body = $this->jsonBody();
		$path = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($body['path'] ?? ''));

		if ($path === '' || !$this->storage()->delete($path)) {
			throw new RuntimeException('File could not be deleted.');
		}

		return ['success' => true];
	}

	protected function fileManagerRmdir(): array {
		$body = $this->jsonBody();
		$path = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($body['path'] ?? ''));

		if ($path === '' || !$this->storage()->rmdir($path)) {
			throw new RuntimeException('Directory could not be deleted.');
		}

		return ['success' => true];
	}

	protected function fileManagerCopy(): array {
		$body = $this->jsonBody();
		$source = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($body['sourcePath'] ?? ''));
		$target = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($body['targetPath'] ?? ''));

		if ($source === '' || $target === '' || !$this->storage()->copy($source, $target)) {
			throw new RuntimeException('File could not be copied.');
		}

		return ['success' => true];
	}

	protected function fileManagerMove(): array {
		$body = $this->jsonBody();
		$source = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($body['sourcePath'] ?? ''));
		$target = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($body['targetPath'] ?? ''));

		if ($source === '' || $target === '' || !$this->storage()->move($source, $target)) {
			throw new RuntimeException('File could not be moved.');
		}

		return ['success' => true];
	}

	protected function download(): void {
		$path = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($_GET['path'] ?? ''));
		if ($path === '') {
			http_response_code(404);
			exit;
		}

		$stat = $this->storage()->stat($path);
		if ($stat === null || ($stat['type'] ?? '') !== 'file') {
			http_response_code(404);
			exit;
		}

		$content = $this->storage()->read($path);
		$name = basename($path);
		$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';

		header('Content-Type: application/octet-stream');
		header('Content-Length: ' . strlen($content));
		header(
			'Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($name)
		);
		header('X-Content-Type-Options: nosniff');
		echo $content;
		exit;
	}

	protected function renderDirectoryTree(
		ilBase3FileManagerRepositoryObjectStorageService $service,
		string $path
	): string {
		$items = $service->listDescriptors($this->objectId(), $path);
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
				$html .= $this->renderDirectoryTree($service, $item['path']);
			}
			else {
				$url = $this->appendQueryParameter(
					$this->ctrl->getLinkTarget($this, 'fmDownload'),
					'path',
					$item['path']
				);
				$html .= '<a href="' . $this->escape($url) . '">' . $this->escape($item['name']) . '</a>';
				if ($item['size'] !== null) {
					$html .= ' <small>(' . $this->escape($this->formatBytes((int) $item['size'])) . ')</small>';
				}
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	protected function fileManagerEndpoints(): array {
		return [
			'list' => $this->ctrl->getLinkTarget($this, 'fmList'),
			'stat' => $this->ctrl->getLinkTarget($this, 'fmStat'),
			'mkdir' => $this->ctrl->getLinkTarget($this, 'fmMkdir'),
			'delete' => $this->ctrl->getLinkTarget($this, 'fmDelete'),
			'rmdir' => $this->ctrl->getLinkTarget($this, 'fmRmdir'),
			'copy' => $this->ctrl->getLinkTarget($this, 'fmCopy'),
			'move' => $this->ctrl->getLinkTarget($this, 'fmMove'),
			'uploadStart' => $this->ctrl->getLinkTarget($this, 'fmUploadStart'),
			'uploadChunk' => $this->ctrl->getLinkTarget($this, 'fmUploadChunk'),
			'uploadStatus' => $this->ctrl->getLinkTarget($this, 'fmUploadStatus'),
			'uploadFinish' => $this->ctrl->getLinkTarget($this, 'fmUploadFinish'),
			'uploadAbort' => $this->ctrl->getLinkTarget($this, 'fmUploadAbort'),
			'download' => $this->ctrl->getLinkTarget($this, 'fmDownload'),
		];
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

	protected function jsonBody(): array {
		$content = file_get_contents('php://input');
		if ($content === false || trim($content) === '') {
			return [];
		}

		$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new InvalidArgumentException('JSON request body must be an object.');
		}
		return $data;
	}

	protected function respondJson(callable $handler): void {
		try {
			$payload = $handler();
			$status = http_response_code();
			if ($status < 200 || $status >= 600) {
				http_response_code(200);
			}
		}
		catch (InvalidArgumentException|JsonException $error) {
			http_response_code(400);
			$payload = ['message' => $error->getMessage()];
		}
		catch (Throwable $error) {
			if (http_response_code() < 400) {
				http_response_code(500);
			}
			$payload = ['message' => $error->getMessage()];
		}

		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		exit;
	}

	protected function storageService(): ilBase3FileManagerRepositoryObjectStorageService {
		return new ilBase3FileManagerRepositoryObjectStorageService();
	}

	protected function uploadService(): ilBase3FileManagerRepositoryObjectUploadService {
		return new ilBase3FileManagerRepositoryObjectUploadService(
			$this->objectId(),
			(int) $this->getDic()->user()->getId(),
			$this->storageService()
		);
	}

	protected function storage(): IFileStorage {
		return $this->storageService()->getStorage($this->objectId());
	}

	protected function assetResolver(): IAssetResolver {
		Base3IliasRuntime::bootOnce(false, true);
		return Base3IliasRuntime::getServiceLocator()->get(IAssetResolver::class);
	}

	protected function objectId(): int {
		return (int) $this->object->getId();
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
		return ($unit === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$unit];
	}

	protected function escape(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	protected function getDic(): Container {
		return $GLOBALS['DIC'];
	}
}
