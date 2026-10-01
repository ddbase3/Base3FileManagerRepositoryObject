<?php declare(strict_types=1);

use Base3\Settings\Api\ISettingsStore;
use Base3Ilias\Base3\Base3IliasFileStorage;
use Base3Ilias\Base3\Base3IliasFileUploadService;
use Base3Ilias\Base3\Base3IliasManagedFileStorageService;
use Base3Ilias\Base3\Base3IliasRuntime;

class ilObjBase3FileManagerRepositoryObject extends ilObjectPlugin {

	private const OWNER_GROUP = 'repo-filemanager';
	private const NOTE_GROUP = 'repo-filemanager';

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
		$this->managedStorageService()->getOrCreateStorageIdentifier(
			self::OWNER_GROUP,
			$this->ownerName(),
			Base3IliasFileStorage::MODE_CONTAINER
		);

		$this->settingsStore()->set(self::NOTE_GROUP, $this->ownerName(), ['note' => '']);
		$this->settingsStore()->save();
	}

	protected function doRead(): void {
		$this->migrateLegacyStorageOwnership();
		$this->managedStorageService()->getOrCreateStorageIdentifier(
			self::OWNER_GROUP,
			$this->ownerName(),
			Base3IliasFileStorage::MODE_CONTAINER
		);
	}

	protected function doUpdate(): void {
	}

	protected function doDelete(): void {
		$this->migrateLegacyStorageOwnership();
		$this->uploadService()->deleteOwnerArtifacts(self::OWNER_GROUP, $this->ownerName());
		$this->managedStorageService()->delete(
			self::OWNER_GROUP,
			$this->ownerName(),
			Base3IliasFileStorage::MODE_CONTAINER
		);

		if ($this->settingsStore()->has(self::NOTE_GROUP, $this->ownerName())) {
			$this->settingsStore()->remove(self::NOTE_GROUP, $this->ownerName());
			$this->settingsStore()->save();
		}
	}

	private function migrateLegacyStorageOwnership(): void {
		$managed = $this->managedStorageService();
		if ($managed->has(self::OWNER_GROUP, $this->ownerName(), Base3IliasFileStorage::MODE_CONTAINER)) {
			return;
		}

		$settings = $this->settingsStore()->get(self::NOTE_GROUP, $this->ownerName(), []);
		$legacyStorageId = trim((string)($settings['storage_rid'] ?? ''));
		if ($legacyStorageId === '') {
			return;
		}

		$managed->adoptStorageIdentifier(
			self::OWNER_GROUP,
			$this->ownerName(),
			Base3IliasFileStorage::MODE_CONTAINER,
			$legacyStorageId
		);

		unset($settings['storage_rid']);
		$this->settingsStore()->set(self::NOTE_GROUP, $this->ownerName(), $settings);
		$this->settingsStore()->save();
	}

	private function managedStorageService(): Base3IliasManagedFileStorageService {
		Base3IliasRuntime::bootOnce(false, true);
		return Base3IliasRuntime::getServiceLocator()->get(Base3IliasManagedFileStorageService::class);
	}

	private function uploadService(): Base3IliasFileUploadService {
		Base3IliasRuntime::bootOnce(false, true);
		return Base3IliasRuntime::getServiceLocator()->get(Base3IliasFileUploadService::class);
	}

	private function settingsStore(): ISettingsStore {
		Base3IliasRuntime::bootOnce(false, true);
		return Base3IliasRuntime::getServiceLocator()->get(ISettingsStore::class);
	}

	private function ownerName(): string {
		return 'obj_' . (int)$this->getId();
	}
}
