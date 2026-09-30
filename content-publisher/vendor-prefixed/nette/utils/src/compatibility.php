<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace CPub\Publisher\Vendor\Nette\Utils;

use CPub\Publisher\Vendor\Nette;

if (false) {
	/** @deprecated use CPub\Publisher\Vendor\Nette\HtmlStringable */
	interface IHtmlString extends \CPub\Publisher\Vendor\Nette\HtmlStringable
	{
	}
} elseif (!interface_exists(IHtmlString::class)) {
	class_alias(\CPub\Publisher\Vendor\Nette\HtmlStringable::class, IHtmlString::class);
}

namespace CPub\Publisher\Vendor\Nette\Localization;

if (false) {
	/** @deprecated use CPub\Publisher\Vendor\Nette\Localization\Translator */
	interface ITranslator extends Translator
	{
	}
} elseif (!interface_exists(ITranslator::class)) {
	class_alias(Translator::class, ITranslator::class);
}
