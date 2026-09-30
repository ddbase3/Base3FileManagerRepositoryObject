# Base3FileManagerRepositoryObject

Base3FileManagerRepositoryObject is a test RepositoryObject plugin for the BASE3 ClientStack FileManager.

The repository object uses the technical type id `xb3f` and is intentionally not copyable in this first implementation.

## Purpose

The plugin tests the ClientStack FileManager in `container` mode inside a normal HTML form that also contains another field. The management page supports drag and drop uploads, upload buttons, chunked uploads, directories and the normal FileManager container operations. The normal repository object view renders the persisted directory structure read-only and offers file downloads.

## Storage

Each repository object owns one ILIAS Resource Storage Service container RID. The RID is persisted in BASE3 `ISettingsStore` under:

```text
group: repo-filemanager
name:  obj_<object_id>
```

Normal file operations are executed through `ResourceFoundation\Api\IFileStorageFactory` and `IFileStorage` in `container` mode.

Container provisioning and final container deletion remain at the ILIAS RepositoryObject boundary because `IFileStorageFactory` intentionally opens existing storages only.

When the ILIAS repository object is deleted, the plugin removes unfinished upload artifacts, removes the complete IRSS container and then removes the SettingsStore record. Uploaded files and temporary upload chunks therefore do not survive deletion of the owning repository object.

## File size scope of this test plugin

The browser FileManager uploads in chunks. This repository object currently finalizes those chunks through the existing string-based `IFileStorage::write(string $path, string $content)` contract and downloads through `IFileStorage::read(string $path): string`.

For the current test scope files are limited to 50 MB. This is deliberate. The plugin does not introduce a separate IRSS streaming path around ResourceFoundation.

A future ResourceFoundation stream-oriented read/write contract should replace the final in-memory string step for substantially larger files. See `docs/IMPLEMENTATION.md`.

## Dependencies

The plugin expects the following BASE3 components to be installed and active:

- Base3 Framework
- Base3Ilias
- ResourceFoundation with `IFileStorageFactory` and the current `IFileStorage` contract
- ClientStack with `assets/filemanager/`

## Installation

Install the plugin at:

```text
<ILIAS>/public/Customizing/global/plugins/Services/Repository/RepositoryObject/Base3FileManagerRepositoryObject
```

Then install and activate it through the ILIAS plugin administration.
