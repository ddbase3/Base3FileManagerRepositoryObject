# Implementation notes

## RepositoryObject lifecycle

The RepositoryObject owns one IRSS container RID.

Creation:

```text
ILIAS RepositoryObject creation
    -> create empty IRSS container
    -> persist RID in ISettingsStore
```

Deletion:

```text
ILIAS RepositoryObject deletion
    -> remove unfinished chunk upload artifacts for the object
    -> resolve persisted RID
    -> remove complete IRSS container with the RepositoryObject stakeholder
    -> remove ISettingsStore dataset
```

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
<base3-artifacts>/xb3f_uploads/obj_<object_id>/<upload_id>/
```

A session is bound to both the repository object id and the current ILIAS user id. Finalization writes the assembled file to the repository object's `IFileStorage`.

The browser-facing upload limit is 50 MB and the backend enforces the same limit.

## Current read/write stream limitation

ResourceFoundation currently exposes the file body through:

```php
public function read(string $path): string;
public function write(string $path, string $content): bool;
```

The FileManager already uploads chunks independently, but finalization must currently assemble the complete file into one PHP string before calling `write()`. Downloads similarly receive the complete file from `read()` before sending it to the browser.

This is accepted for the initial RepositoryObject test where files stay below 50 MB.

For substantially larger files the correct architecture change belongs in ResourceFoundation. A future stream-based storage contract should allow the RepositoryObject upload service to pass a stream into the active storage implementation and should allow downloads to consume a stream without materializing the whole file body in memory.

This plugin intentionally does not bypass `IFileStorage` with a second direct IRSS upload/download path.

## Copy and move

The FileManager HTTP backend maps file copy and move directly to the current `IFileStorage::copy()` and `IFileStorage::move()` operations. RepositoryObject copy remains disabled and is a separate lifecycle concern.
