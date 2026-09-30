<?php declare(strict_types=1);

class ilObjBase3FileManagerRepositoryObjectListGUI extends ilObjectPluginListGUI {

	public function initType(): void {
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
}
