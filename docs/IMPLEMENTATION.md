# Implementation notes

## RepositoryObject lifecycle

The RepositoryObject owns one managed IRSS container through the shared Base3Ilias FileManager services.

Creation:

```text
ILIAS RepositoryObject creation
    -> Base3IliasManagedFileStorageService
    -> create empty IRSS container
    -> persist managed owner mapping
```

Deletion:

```text
ILIAS RepositoryObject deletion
    -> Base3IliasFileUploadService removes unfinished chunks
    -> Base3IliasManagedFileStorageService removes complete IRSS container
    -> plugin removes its note settings
```

Older test objects are migrated once from the former plugin-local `storage_rid` setting into the central Base3Ilias managed owner mapping. After that migration the legacy field is removed and only the shared service path remains.

RepositoryObject copy is disabled in this first implementation. There is therefore no RID cloning or file duplication lifecycle yet.

## FileManager form binding

The file management page contains one normal form with:

- a normal `note` textarea
- the ClientStack FileManager in `container` mode
- the hidden `file_container` value created by `FormBindingAdapter`
- the normal save button

The FileManager waits for active uploads before the form submit proceeds. The server verifies that the submitted `file_container` value equals the RID owned by the current repository object. The browser can therefore not select another storage by manipulating the hidden field.

## Chunk upload

Chunk uploads are stored temporarily below the BASE3 artifact directory:

```text
<base3-artifacts>/filemanager/<owner-hash>/<upload_id>/
```

A session is bound to the managed owner key and the current ILIAS user id. Finalization writes the assembled file through the shared Base3Ilias managed `IFileStorage`.

The browser-facing upload limit is 50 MB and the backend enforces the same limit.

## Current read/write stream limitation

ResourceFoundation currently exposes the file body through:

```php
public function read(string $path): string;
public function write(string $path, string $content): bool;
```

The FileManager already uploads chunks independently, but finalization must currently assemble the complete file into one PHP string before calling `write()`. Downloads similarly receive the complete file from `read()` before sending it to the browser.

This is accepted for the initial RepositoryObject test where files stay below 50 MB.

For substantially larger files the correct architecture change belongs in ResourceFoundation. A future stream-based storage contract should allow the shared Base3Ilias upload service to pass a stream into the active storage implementation and should allow downloads to consume a stream without materializing the whole file body in memory.

This plugin intentionally does not bypass `IFileStorage` with a second direct IRSS upload/download path.

## Copy and move

The FileManager HTTP backend maps file copy and move directly to the current `IFileStorage::copy()` and `IFileStorage::move()` operations. RepositoryObject copy remains disabled and is a separate lifecycle concern.
