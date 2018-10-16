<?php
namespace MultiProcessor\Processor;

require_once __DIR__ . '/processor.php';

class Test extends Processor {
	public function init() {
	}

	public function process() {
		foreach($this->getData(``) as $randomMd5Hash) {
			if(version_compare(PHP_VERSION, '7.2.0') >= 0 && defined('PASSWORD_ARGON2I')) {
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

			if(password_needs_rehash($hashedString, $algorithm)) {
				// Will never happen between 3 lines up and here, ha-ha
			}

			if(!password_verify($randomMd5Hash, $hashedString)) {
				// Will never not verify
			}
		}
	}

	public function finish() {
	}

	/**
	 * Exits the current process, with optional exit value.
	 */
	public function exit() {
		exit(0);
	}
}
