<?php declare(strict_types=1);

use ILIAS\ResourceStorage\Identification\ResourceIdentification;
use ILIAS\ResourceStorage\Stakeholder\AbstractResourceStakeholder;

final class ilBase3FileManagerRepositoryObjectStakeholder extends AbstractResourceStakeholder {

	public function getId(): string {
		return 'xb3f_file_storage';
	}

	public function getConsumerNameForPresentation(): string {
		return 'BASE3 FileManager Repository Object';
	}

	public function isResourceInUse(ResourceIdentification $identification): bool {
		return true;
	}

	public function getOwnerOfResource(ResourceIdentification $identification): int {
		return $this->default_owner;
	}

	public function getOwnerOfNewResources(): int {
		return $this->default_owner;
	}
}
