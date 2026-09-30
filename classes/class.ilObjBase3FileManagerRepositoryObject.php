<?php declare(strict_types=1);

require_once __DIR__ . '/class.ilBase3FileManagerRepositoryObjectStorageService.php';
require_once __DIR__ . '/class.ilBase3FileManagerRepositoryObjectUploadService.php';

class ilObjBase3FileManagerRepositoryObject extends ilObjectPlugin {

	public function __construct(int $a_ref_id = 0) {
		parent::__construct($a_ref_id);
	}

	protected function initType(): void {
		$this->setType(ilBase3FileManagerRepositoryObjectPlugin::ID);
	}

	public function getGuiClass(): string {
		return 'ilObjBase3FileManagerRepositoryObjectGUI';
	}

	public function initCommands(): array {
		return [
			[
				'permission' => 'read',
				'cmd' => 'showContent',
				'default' => true,
			]
		];
	}

	protected function doCreate(bool $clone_mode = false): void {
		(new ilBase3FileManagerRepositoryObjectStorageService())
			->createObjectStorage((int) $this->getId());
	}

	protected function doRead(): void {
	}

	protected function doUpdate(): void {
	}

	protected function doDelete(): void {
		$objectId = (int) $this->getId();
		$storageService = new ilBase3FileManagerRepositoryObjectStorageService();

		ilBase3FileManagerRepositoryObjectUploadService::deleteObjectArtifacts($objectId);
		$storageService->deleteObjectStorage($objectId);
	}
}
