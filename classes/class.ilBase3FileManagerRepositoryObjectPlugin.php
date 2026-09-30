<?php declare(strict_types=1);

class ilBase3FileManagerRepositoryObjectPlugin extends ilRepositoryObjectPlugin {

	public const ID = 'xb3f';

	public function getPluginName(): string {
		return 'Base3FileManagerRepositoryObject';
	}

	protected function uninstallCustom(): void {
		// noop
	}

	public function allowCopy(): bool {
		return false;
	}
}
