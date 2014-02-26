<?php

/**
 * DO NOT EDIT THIS FILE.
 *
 * This file is subject to be overwritten by a Craft update at any time.
 *
 * If you want to change any of these settings, copy it into
 * craft/config/filecache.php, and make your change there.
 */

return array(
	/**
	 * The file system path to use for caching. If empty, Craft will default to craft/app/storage/runtime/cache.
	 */
	'cachePath' => '',

	/**
	 * The probability (parts per million) that garbage collection (GC) should be performed when storing a piece of data in the cache. Defaults to 100, meaning 0.01% chance.
	 */
	'gcProbability' => 100,
);
