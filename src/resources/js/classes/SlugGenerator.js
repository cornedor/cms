/**
 * Slug Generator
 */
Craft.SlugGenerator = Craft.BaseInputGenerator.extend({

	generateTargetValue: function(sourceVal)
	{
		// Remove HTML tags
		sourceVal = sourceVal.replace("/<(.*?)>/g", '');

		// Make it lowercase
		sourceVal = sourceVal.toLowerCase();

		// Get the "words".  Split on anything that is not a unicode letter or number.
		var words = Craft.filterArray(XRegExp.matchChain(sourceVal, [XRegExp('[\\p{L}\\p{N}]+')]));

		if (words.length)
		{
			return words.join('-');
		}
		else
		{
			return '';
		}
	}
});
