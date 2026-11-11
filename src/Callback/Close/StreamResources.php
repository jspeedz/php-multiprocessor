<?php
namespace Jspeedz\MultiProcessor\Callback\Close;

use Closure;
use Jspeedz\MultiProcessor\Exception\UnsupportedException;

/**
 * Closes all open stream resources, so forked children don't share file descriptors with the parent.
 *
 * Everything is closed except PHP's standard streams, in-memory streams and the resources passed in $keepOpen.
 * Pass handles that must survive in $keepOpen, most importantly files held with flock():
 * closing those would release the lock.
 *
 * Please note, this class is experimental
 */
class StreamResources {
    /**
     * Standard streams that are never closed.
     */
    private const array STANDARD_STREAM_URIS = [
        'php://stdin',
        'php://stdout',
        'php://stderr',
        'php://output',
        'php://input',
    ];

    /**
     * In-memory streams (php://memory, php://temp) don't hold a shared file descriptor.
     */
    private const array IN_MEMORY_STREAM_TYPES = [
        'MEMORY',
        'TEMP',
    ];

    /**
     * @param list<resource> $keepOpen Resources to leave open, like lock file handles
     */
    public function __construct(
        private readonly array $keepOpen = [],
    ) {}

    /**
     * @return Closure
     */
    public function getCallback(): Closure {
        $keepOpen = $this->keepOpen;

        return function() use ($keepOpen): void {
            // Newest first: wrapping streams (compress.zlib://, php://filter, ...) open their inner stream before
            // themselves. Closing the outer stream frees the inner one, while closing the inner one first crashes PHP.
            foreach(array_reverse(get_resources()) as $resource) {
                $type = get_resource_type($resource);
                if(in_array($type, ['Unknown', 'stream-context'], true)) {
                    // Already closed, or nothing to close
                    continue;
                }
                if($type !== 'stream') {
                    // See http://php.net/manual/en/resource.php for how to close this type
                    throw new UnsupportedException('Unknown resource type (' . $type . ')');
                }
                if(in_array($resource, $keepOpen, true)) {
                    continue;
                }

                $meta = stream_get_meta_data($resource);
                if(
                    (isset($meta['uri']) && in_array($meta['uri'], self::STANDARD_STREAM_URIS, true)) ||
                    in_array($meta['stream_type'], self::IN_MEMORY_STREAM_TYPES, true)
                ) {
                    continue;
                }

                if($meta['stream_type'] === 'dir') {
                    closedir($resource);
                }
                else if(!isset($meta['uri']) && $meta['stream_type'] === 'STDIO') {
                    // Assume this is a process handle, like popen()
                    pclose($resource);
                }
                else {
                    // Files, sockets, http(s), ftp, compress.*, php://fd/N, php://filter, user-space wrappers, ...
                    fclose($resource);
                }
            }
        };
    }
}
