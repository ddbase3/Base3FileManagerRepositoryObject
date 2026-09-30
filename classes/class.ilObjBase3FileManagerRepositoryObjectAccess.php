<?php declare(strict_types=1);

class ilObjBase3FileManagerRepositoryObjectAccess extends ilObjectPluginAccess {

	public function _checkAccess(string $cmd, string $permission, int $ref_id, int $obj_id, ?int $user_id = null): bool {
		return true;
	}
}
