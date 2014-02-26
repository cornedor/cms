<?php

/**
 * DO NOT EDIT THIS FILE.
 *
 * This file is subject to be overwritten by a Craft update at any time.
 *
 * If you want to change any of these settings, copy it into
 * craft/config/dbcache.php, and make your change there.
 */

return array(
	array(
		/**
		 * The probability (parts per million) that garbage collection (GC) should be performed when storing a piece of data in the cache. Defaults to 100, meaning 0.01% chance.
		 */
		'gcProbability' => 100,

		/**
		 * The name of the cache table in the database.  Note that Craft will add the table prefix from your craft/config/db.php file.
		 */
		'cacheTableName' => 'cache',
	),
);
