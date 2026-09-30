<?php declare(strict_types=1);

require_once __DIR__ . '/class.ilBase3FileManagerRepositoryObjectStorageService.php';

final class ilBase3FileManagerRepositoryObjectUploadService {

	public const MAX_FILE_SIZE = 50 * 1024 * 1024;
	public const MAX_CHUNK_SIZE = 10 * 1024 * 1024;

	public function __construct(
		private readonly int $objectId,
		private readonly int $userId,
		private readonly ilBase3FileManagerRepositoryObjectStorageService $storageService
	) {
		if ($objectId <= 0 || $userId <= 0) {
			throw new InvalidArgumentException('Upload service requires repository object and user identifiers.');
		}
	}

	public function start(array $data): array {
		$requestedUploadId = trim((string) ($data['uploadId'] ?? ''));
		if ($requestedUploadId !== '' && $this->sessionExists($requestedUploadId)) {
			$meta = $this->loadMeta($requestedUploadId);
			return [
				'uploadId' => $requestedUploadId,
				'uploadedChunks' => $this->getUploadedChunks($requestedUploadId, $meta),
			];
		}

		$name = $this->normalizeFileName((string) ($data['name'] ?? ''));
		$path = ilBase3FileManagerRepositoryObjectStorageService::normalizePath((string) ($data['path'] ?? ''));
		$size = (int) ($data['size'] ?? -1);
		$chunkSize = (int) ($data['chunkSize'] ?? 0);
		$totalChunks = (int) ($data['totalChunks'] ?? 0);

		if ($size < 0 || $size > self::MAX_FILE_SIZE) {
			throw new InvalidArgumentException('Files larger than 50 MB are not supported by this test repository object.');
		}
		if ($chunkSize <= 0 || $chunkSize > self::MAX_CHUNK_SIZE) {
			throw new InvalidArgumentException('Invalid upload chunk size.');
		}

		$expectedChunks = max(1, (int) ceil($size / $chunkSize));
		if ($totalChunks !== $expectedChunks) {
			throw new InvalidArgumentException('Upload chunk count does not match file size.');
		}

		$uploadId = bin2hex(random_bytes(16));
		$sessionDir = $this->sessionDir($uploadId, true);
		$meta = [
			'upload_id' => $uploadId,
			'object_id' => $this->objectId,
			'user_id' => $this->userId,
			'path' => $path,
			'name' => $name,
			'size' => $size,
			'type' => (string) ($data['type'] ?? ''),
			'last_modified' => $data['lastModified'] ?? null,
			'chunk_size' => $chunkSize,
			'total_chunks' => $totalChunks,
			'created_at' => time(),
		];

		$this->writeJson($sessionDir . DIRECTORY_SEPARATOR . 'meta.json', $meta);

		return [
			'uploadId' => $uploadId,
			'uploadedChunks' => [],
		];
	}

	public function saveChunk(array $post, array $files): array {
		$uploadId = trim((string) ($post['uploadId'] ?? ''));
		$index = (int) ($post['index'] ?? -1);
		$meta = $this->loadMeta($uploadId);

		if ($index < 0 || $index >= (int) $meta['total_chunks']) {
			throw new InvalidArgumentException('Invalid upload chunk index.');
		}
		if (!isset($files['chunk']) || !is_array($files['chunk'])) {
			throw new InvalidArgumentException('Upload chunk is missing.');
		}

		$file = $files['chunk'];
		if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new RuntimeException('Upload chunk could not be received.');
		}

		$tmpName = (string) ($file['tmp_name'] ?? '');
		if ($tmpName === '' || !is_file($tmpName)) {
			throw new RuntimeException('Upload chunk temporary file is missing.');
		}

		$content = file_get_contents($tmpName);
		if (!is_string($content)) {
			throw new RuntimeException('Upload chunk could not be read.');
		}

		$expectedSize = $this->expectedChunkSize($meta, $index);
		if (strlen($content) !== $expectedSize) {
			throw new InvalidArgumentException('Upload chunk size does not match the upload session.');
		}

		$chunkPath = $this->chunkPath($uploadId, $index);
		if (file_put_contents($chunkPath, $content, LOCK_EX) === false) {
			throw new RuntimeException('Upload chunk could not be stored.');
		}

		return [
			'uploadId' => $uploadId,
			'index' => $index,
			'received' => true,
		];
	}

	public function status(string $uploadId): array {
		$meta = $this->loadMeta($uploadId);

		return [
			'uploadId' => $uploadId,
			'uploadedChunks' => $this->getUploadedChunks($uploadId, $meta),
		];
	}

	public function finish(string $uploadId): array {
		$meta = $this->loadMeta($uploadId);
		$content = '';

		for ($index = 0; $index < (int) $meta['total_chunks']; $index++) {
			$chunkPath = $this->chunkPath($uploadId, $index);
			if (!is_file($chunkPath)) {
				throw new RuntimeException('Upload is incomplete. Missing chunk ' . $index . '.');
			}

			$chunk = file_get_contents($chunkPath);
			if (!is_string($chunk)) {
				throw new RuntimeException('Upload chunk could not be read during finalization.');
			}
			$content .= $chunk;
		}

		if (strlen($content) !== (int) $meta['size']) {
			throw new RuntimeException('Finalized upload size does not match the announced file size.');
		}

		$targetPath = ilBase3FileManagerRepositoryObjectStorageService::joinPath(
			(string) $meta['path'],
			(string) $meta['name']
		);
		$storage = $this->storageService->getStorage($this->objectId);

		if (!$storage->write($targetPath, $content)) {
			throw new RuntimeException('File storage rejected the finalized upload.');
		}

		$descriptor = $this->storageService->statDescriptor($this->objectId, $targetPath);
		if ($descriptor === null) {
			throw new RuntimeException('Uploaded file could not be resolved after finalization.');
		}

		$this->removeSession($uploadId);
		return $descriptor;
	}

	public function abort(string $uploadId): array {
		if ($uploadId !== '' && $this->sessionExists($uploadId)) {
			$this->loadMeta($uploadId);
			$this->removeSession($uploadId);
		}

		return [
			'uploadId' => $uploadId,
			'aborted' => true,
		];
	}

	public static function deleteObjectArtifacts(int $objectId): void {
		if ($objectId <= 0) {
			throw new InvalidArgumentException('Repository object id must be positive.');
		}

		$path = self::objectBaseDir($objectId);
		if (!is_dir($path)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			$itemPath = $item->getPathname();
			if ($item->isDir() && !$item->isLink()) {
				if (!rmdir($itemPath)) {
					throw new RuntimeException('Could not remove FileManager upload directory: ' . $itemPath);
				}
				continue;
			}

			if (!unlink($itemPath)) {
				throw new RuntimeException('Could not remove FileManager upload artifact: ' . $itemPath);
			}
		}

		if (!rmdir($path)) {
			throw new RuntimeException('Could not remove FileManager upload directory: ' . $path);
		}
	}

	private function loadMeta(string $uploadId): array {
		$this->assertUploadId($uploadId);
		$metaPath = $this->sessionDir($uploadId) . DIRECTORY_SEPARATOR . 'meta.json';
		if (!is_file($metaPath)) {
			throw new InvalidArgumentException('Upload session was not found.');
		}

		$content = file_get_contents($metaPath);
		if (!is_string($content)) {
			throw new RuntimeException('Upload session metadata could not be read.');
		}

		$meta = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($meta)) {
			throw new RuntimeException('Upload session metadata is invalid.');
		}
		if ((int) ($meta['object_id'] ?? 0) !== $this->objectId || (int) ($meta['user_id'] ?? 0) !== $this->userId) {
			throw new RuntimeException('Upload session does not belong to the current repository object and user.');
		}

		return $meta;
	}

	private function getUploadedChunks(string $uploadId, array $meta): array {
		$uploaded = [];
		for ($index = 0; $index < (int) $meta['total_chunks']; $index++) {
			if (is_file($this->chunkPath($uploadId, $index))) {
				$uploaded[] = $index;
			}
		}
		return $uploaded;
	}

	private function expectedChunkSize(array $meta, int $index): int {
		$size = (int) $meta['size'];
		$chunkSize = (int) $meta['chunk_size'];
		$offset = $index * $chunkSize;

		if ($size === 0) {
			return 0;
		}

		return max(0, min($chunkSize, $size - $offset));
	}

	private function normalizeFileName(string $name): string {
		$name = trim(str_replace('\\', '/', $name));
		if ($name === '' || str_contains($name, "\0") || str_contains($name, '/')) {
			throw new InvalidArgumentException('Invalid upload file name.');
		}
		if ($name === '.' || $name === '..') {
			throw new InvalidArgumentException('Invalid upload file name.');
		}
		return $name;
	}

	private function baseDir(bool $create = false): string {
		$path = self::objectBaseDir($this->objectId);

		if ($create && !is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
			throw new RuntimeException('Could not create FileManager upload directory.');
		}

		return $path;
	}

	private static function objectBaseDir(int $objectId): string {
		return rtrim(DIR_BASE3_ARTIFACTS, '/\\')
			. DIRECTORY_SEPARATOR
			. 'xb3f_uploads'
			. DIRECTORY_SEPARATOR
			. 'obj_'
			. $objectId;
	}

	private function sessionDir(string $uploadId, bool $create = false): string {
		$this->assertUploadId($uploadId);
		$path = $this->baseDir($create) . DIRECTORY_SEPARATOR . $uploadId;

		if ($create && !is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
			throw new RuntimeException('Could not create FileManager upload session directory.');
		}

		return $path;
	}

	private function chunkPath(string $uploadId, int $index): string {
		return $this->sessionDir($uploadId) . DIRECTORY_SEPARATOR . 'chunk_' . $index . '.part';
	}

	private function sessionExists(string $uploadId): bool {
		$this->assertUploadId($uploadId);
		return is_file($this->sessionDir($uploadId) . DIRECTORY_SEPARATOR . 'meta.json');
	}

	private function assertUploadId(string $uploadId): void {
		if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
			throw new InvalidArgumentException('Invalid upload session id.');
		}
	}

	private function writeJson(string $path, array $data): void {
		$content = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		if (file_put_contents($path, $content, LOCK_EX) === false) {
			throw new RuntimeException('Upload session metadata could not be stored.');
		}
	}

	private function removeSession(string $uploadId): void {
		$path = $this->sessionDir($uploadId);
		if (!is_dir($path)) {
			return;
		}

		$files = scandir($path);
		if (is_array($files)) {
			foreach ($files as $file) {
				if ($file === '.' || $file === '..') {
					continue;
				}
				$filePath = $path . DIRECTORY_SEPARATOR . $file;
				if (is_file($filePath)) {
					unlink($filePath);
				}
			}
		}

		if (is_dir($path)) {
			rmdir($path);
		}
	}
}
