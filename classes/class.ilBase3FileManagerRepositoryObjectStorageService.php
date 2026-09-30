<?php declare(strict_types=1);

use Base3\Settings\Api\ISettingsStore;
use Base3Ilias\Base3\Base3IliasRuntime;
use ILIAS\Filesystem\Stream\Streams;
use ResourceFoundation\Api\IFileStorage;
use ResourceFoundation\Api\IFileStorageFactory;

require_once __DIR__ . '/class.ilBase3FileManagerRepositoryObjectStakeholder.php';

final class ilBase3FileManagerRepositoryObjectStorageService {

	public const CONFIG_GROUP = 'repo-filemanager';
	public const STORAGE_MODE = 'container';

	private ISettingsStore $settingsStore;
	private IFileStorageFactory $fileStorageFactory;

	public function __construct() {
		Base3IliasRuntime::bootOnce(false, true);
		$services = Base3IliasRuntime::getServiceLocator();
		$this->settingsStore = $services->get(ISettingsStore::class);
		$this->fileStorageFactory = $services->get(IFileStorageFactory::class);
	}

	public function createObjectStorage(int $objectId): string {
		if ($objectId <= 0) {
			throw new InvalidArgumentException('Repository object id must be positive.');
		}

		$configName = self::buildConfigName($objectId);
		if ($this->settingsStore->has(self::CONFIG_GROUP, $configName)) {
			throw new RuntimeException('FileManager storage settings already exist for object ' . $objectId . '.');
		}

		$rid = $this->createContainer();

		try {
			$this->settingsStore->set(self::CONFIG_GROUP, $configName, [
				'storage_rid' => $rid,
				'note' => '',
			]);
			$this->settingsStore->save();
		}
		catch (Throwable $error) {
			$this->removeContainer($rid);
			throw $error;
		}

		return $rid;
	}

	public function deleteObjectStorage(int $objectId): void {
		$configName = self::buildConfigName($objectId);
		$settings = $this->settingsStore->get(self::CONFIG_GROUP, $configName, []);
		$rid = trim((string) ($settings['storage_rid'] ?? ''));

		if ($rid !== '') {
			$this->removeContainer($rid);
		}

		if ($this->settingsStore->has(self::CONFIG_GROUP, $configName)) {
			$this->settingsStore->remove(self::CONFIG_GROUP, $configName);
			$this->settingsStore->save();
		}
	}

	public function getStorage(int $objectId): IFileStorage {
		return $this->fileStorageFactory->openStorage(
			$this->getStorageIdentifier($objectId),
			self::STORAGE_MODE
		);
	}

	public function getStorageIdentifier(int $objectId): string {
		$settings = $this->getObjectSettings($objectId);
		$rid = trim((string) ($settings['storage_rid'] ?? ''));

		if ($rid === '') {
			throw new RuntimeException('No FileManager storage is configured for repository object ' . $objectId . '.');
		}

		return $rid;
	}

	public function getNote(int $objectId): string {
		$settings = $this->getObjectSettings($objectId);
		return (string) ($settings['note'] ?? '');
	}

	public function saveNote(int $objectId, string $note): void {
		$configName = self::buildConfigName($objectId);
		$settings = $this->getObjectSettings($objectId);
		$settings['note'] = $note;
		$this->settingsStore->set(self::CONFIG_GROUP, $configName, $settings);
		$this->settingsStore->save();
	}

	public function listDescriptors(int $objectId, string $path = ''): array {
		$path = self::normalizePath($path);
		$storage = $this->getStorage($objectId);
		$items = [];

		foreach ($storage->list($path) as $item) {
			if (!is_array($item) || !isset($item['name'], $item['type'])) {
				continue;
			}

			$name = (string) $item['name'];
			$itemPath = self::joinPath($path, $name);
			$items[] = $this->descriptorFromStorageData($itemPath, $name, $item);
		}

		return $items;
	}

	public function statDescriptor(int $objectId, string $path): ?array {
		$path = self::normalizePath($path);
		$stat = $this->getStorage($objectId)->stat($path);

		if ($stat === null) {
			return null;
		}

		$name = $path === '' ? '/' : basename($path);
		return $this->descriptorFromStorageData($path, $name, $stat);
	}

	public static function buildConfigName(int $objectId): string {
		return 'obj_' . $objectId;
	}

	public static function normalizePath(string $path): string {
		$path = str_replace('\\', '/', trim($path));
		$path = ltrim($path, '/');

		if ($path === '' || $path === '.') {
			return '';
		}

		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new InvalidArgumentException('Parent path segments are not allowed.');
			}
			if (str_contains($segment, "\0")) {
				throw new InvalidArgumentException('Path contains an invalid null byte.');
			}
			$segments[] = $segment;
		}

		return implode('/', $segments);
	}

	public static function joinPath(string $path, string $name): string {
		$path = self::normalizePath($path);
		$name = self::normalizePath($name);

		if ($name === '') {
			return $path;
		}

		return $path === '' ? $name : $path . '/' . $name;
	}

	private function getObjectSettings(int $objectId): array {
		return $this->settingsStore->get(
			self::CONFIG_GROUP,
			self::buildConfigName($objectId),
			[]
		);
	}

	private function createContainer(): string {
		$tempPath = rtrim(sys_get_temp_dir(), '/\\')
			. DIRECTORY_SEPARATOR
			. 'xb3f_'
			. bin2hex(random_bytes(12))
			. '.zip';
		$handle = null;

		try {
			if (file_put_contents($tempPath, "PK\x05\x06" . str_repeat("\x00", 18)) === false) {
				throw new RuntimeException('Could not create temporary empty ZIP container.');
			}

			$handle = fopen($tempPath, 'rb');
			if (!is_resource($handle)) {
				throw new RuntimeException('Could not open temporary ZIP container.');
			}

			$identification = $GLOBALS['DIC']
				->resourceStorage()
				->manageContainer()
				->containerFromStream(
					Streams::ofResource($handle),
					new ilBase3FileManagerRepositoryObjectStakeholder()
				);

			return $identification->serialize();
		}
		finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
			if (is_file($tempPath)) {
				unlink($tempPath);
			}
		}
	}

	private function removeContainer(string $rid): void {
		$manager = $GLOBALS['DIC']->resourceStorage()->manageContainer();
		$identification = $manager->find($rid);

		if ($identification === null) {
			return;
		}

		$manager->remove(
			$identification,
			new ilBase3FileManagerRepositoryObjectStakeholder()
		);

		if ($manager->find($rid) !== null) {
			throw new RuntimeException('ILIAS resource container could not be removed: ' . $rid);
		}
	}

	private function descriptorFromStorageData(string $path, string $name, array $data): array {
		$type = (string) ($data['type'] ?? '');
		if (!in_array($type, ['file', 'dir'], true)) {
			throw new RuntimeException('Unsupported file storage entry type: ' . $type);
		}

		return [
			'id' => $path === '' ? '/' : $path,
			'name' => $name,
			'path' => $path,
			'kind' => $type === 'dir' ? 'directory' : 'file',
			'size' => $type === 'file' ? (int) ($data['size'] ?? 0) : null,
			'mimeType' => '',
			'modifiedAt' => (string) ($data['modified'] ?? ''),
		];
	}
}
