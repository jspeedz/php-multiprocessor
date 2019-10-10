<?php

use Jspeedz\MultiProcessor\Processor\ProcessorAbstract;

class TestProcessor extends ProcessorAbstract {
    /**
     * Use this if you need to do some custom task before processing a chunk
     */
    public function initialize(): void {
        // Do some work before the chunk is processed
    }

    public function process(): void {
        foreach ($this->getData() as $randomMd5Hash) {
            if (version_compare(PHP_VERSION, '7.2.0') >= 0 && defined('PASSWORD_ARGON2I')) {
                $algorithm = PASSWORD_ARGON2I;
                $options = [];
            }
            else {
                $algorithm = PASSWORD_BCRYPT;
                $options = [
                    'cost' => rand(4, 10),
                ];
            }

            $hashedString = password_hash($randomMd5Hash, $algorithm, $options);

            if (password_needs_rehash($hashedString, $algorithm)) {
                // Will never happen between 3 lines up and here, ha-ha
            }

            if (!password_verify($randomMd5Hash, $hashedString)) {
                // Will never not verify
            }
        }
    }

    /**
     * Use this if you need to do some custom task after finishing a chunk
     */
    public function finish(): void {
        // Do some work after the chunk has been completed..
    }

    public function exit(): void {
        // Set my own exit code
        exit(0);
    }
}
