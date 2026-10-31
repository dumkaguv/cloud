<?php
// S3 storage for capsule attachments. The EC2 instance role supplies credentials.

function storage_client(): Aws\S3\S3Client
{
    static $client = null;
    if ($client === null) {
        $client = new Aws\S3\S3Client([
            'version' => 'latest',
            'region' => config('AWS_REGION', 'eu-central-1'),
        ]);
    }
    return $client;
}

function storage_bucket(): string
{
    $bucket = config('S3_BUCKET');
    if ($bucket === null) {
        throw new RuntimeException('S3_BUCKET is not configured.');
    }
    return $bucket;
}

function storage_key(string $storedName): string
{
    return 'uploads/' . basename($storedName);
}

// Store a validated uploaded file and return its random name, as before.
function storage_save(array $uploadedFile, string $extension): string
{
    if (!is_uploaded_file($uploadedFile['tmp_name'])) {
        throw new RuntimeException('Could not read the uploaded file.');
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $contentType = (new finfo(FILEINFO_MIME_TYPE))->file($uploadedFile['tmp_name']);
    if ($contentType === false) {
        throw new RuntimeException('Could not determine the uploaded file type.');
    }

    storage_client()->putObject([
        'Bucket' => storage_bucket(),
        'Key' => storage_key($storedName),
        'SourceFile' => $uploadedFile['tmp_name'],
        'ContentType' => $contentType,
    ]);
    return $storedName;
}

function storage_delete(?string $storedName): void
{
    if ($storedName === null || $storedName === '') {
        return;
    }

    // With versioning enabled, ordinary deletion creates a recoverable delete marker.
    storage_client()->deleteObject([
        'Bucket' => storage_bucket(),
        'Key' => storage_key($storedName),
    ]);
}

// Called only after the capsule controller has checked visibility and opening time.
function storage_stream(string $storedName, string $mime, string $downloadName, string $disposition): void
{
    $safeName = str_replace(["\r", "\n"], '_', $downloadName);
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $safeName);
    $contentDisposition = sprintf(
        '%s; filename="%s"; filename*=UTF-8\'\'%s',
        $disposition === 'inline' ? 'inline' : 'attachment',
        $asciiName,
        rawurlencode($safeName)
    );

    $command = storage_client()->getCommand('GetObject', [
        'Bucket' => storage_bucket(),
        'Key' => storage_key($storedName),
        'ResponseContentType' => $mime,
        'ResponseContentDisposition' => $contentDisposition,
    ]);
    $request = storage_client()->createPresignedRequest($command, '+5 minutes');

    header('Cache-Control: no-store');
    header('Location: ' . (string) $request->getUri(), true, 302);
}
