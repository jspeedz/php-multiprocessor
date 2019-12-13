<?php
namespace Jspeedz\MultiProcessor\Callback\Close;

use Closure;
use Exception;

/**
 * Please note, this class is experimental
 */
class StreamResources {
    /**
     * @return Closure
     */
    public function getCallback(): Closure {
        return function() {
            $resources = get_resources();

            foreach($resources as $resource) {
                switch(get_resource_type($resource)) {
                    case 'stream':
                        $meta = stream_get_meta_data($resource);
                        if(isset($meta['uri']) && !in_array($meta['uri'], [
                            'php://stdin',
                            'php://stdout',
                            'php://stderr',
                            'php://output',
                            'php://output',
                            'php://input',
                            'php://filter',
                            'php://memory',
                            'php://temp',
                        ])) {
                            switch($meta['wrapper_type']) {
                                case 'plainfile':
                                case 'http':
                                    switch($meta['stream_type']) {
                                        case 'dir':
                                            closedir($resource);
                                            break;
                                        case 'STDIO':
                                        case 'tcp_socket/ssl':
                                            // File
                                            // Http connection
                                            fclose($resource);
                                            break;
                                        default:
                                            // See http://php.net/manual/en/resource.php for how to close this type
                                            throw new Exception('Unknown stream type (' . $meta['stream_type'] . ')');
                                    }
                                    break;
                            }
                        }
                        else if(!isset($meta['uri']) && isset($meta['stream_type']) && $meta['stream_type'] === 'STDIO') {
                            // Assume this is a process handle, like popen(), fsockopen() etc.
                            pclose($resource);
                        }
                        else {
                            // See http://php.net/manual/en/resource.php for how to close this type
                            throw new Exception('Unknown stream type (' . json_encode($meta) . ')');
                        }
                        break;
                    case 'Unknown':
                    case 'stream-context':
                        break;
                    default:
                        throw new Exception('Unknown resource type (' . get_resource_type($resource) . ')');
                }
            }
        };
    }
}
