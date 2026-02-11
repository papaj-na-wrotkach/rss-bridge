<?php
declare(strict_types=1);

class EHentaiBridge extends BridgeAbstract
{
	// Parameter names that can be used as URL query parameter keys
	const QUERY_PARAM_KEYS = [
		'f_search'   => null,
		'f_sfl'      => null,
		'f_sfu'      => null,
		'f_sft'      => null,
		'f_sh'       => null,
		'f_sto'      => null,
		'f_spf'      => null,
		'f_spt'      => null,
		'f_srdd'     => null,
		'favcat'     => null,
		'inline_set' => null,
	];

	// shared parameters
	private const _SEARCH_PARAMETER = [
		'f_search' => [
			'name' => 'Search Query',
			'type' => 'text',
		],
	];
	private const _FILTER_PARAMETERS = [
		'f_sfl' => [
			'name'         => 'Enable Custom Language Filters',
			'type'         => 'checkbox',
			'defaultValue' => 'checked',
		],
		'f_sfu' => [
			'name'         => 'Enable Custom Uploader Filters',
			'type'         => 'checkbox',
			'defaultValue' => 'checked',
		],
		'f_sft' => [
			'name'         => 'Enable Custom Tag Filters',
			'type'         => 'checkbox',
			'defaultValue' => 'checked',
		],
	];
	private const _CATEGORY_PARAMETER = [
		'categories' => [
			'name'   => 'Gallery Categories',
			'title'  => 'gallery categories to include in the results',
			'type'   => 'multi-list',
			'values' => [
				'Doujinshi' => 2,
				'Manga'     => 4,
				'Artist CG'  => 8,
				'Game CG'    => 16,
				'Western'   => 512,
				'Non-H'      => 256,
				'Image Set'  => 32,
				'Cosplay'   => 64,
				'Asian Porn' => 128,
				'Misc'      => 1,
			],
			// 'defaultValue' => [ 1, 2, 4, 8, 16, 32, 64, 128, 256, 512, ],
		],
	];
	private const _SHARED_PARAMETERS = [
		...self::_SEARCH_PARAMETER,
		...self::_CATEGORY_PARAMETER,
		...self::_FILTER_PARAMETERS,
		'f_sh' => [
			'name' => 'Browse Expunged',
			'type' => 'checkbox',
		],
		'f_sto' => [
			'name' => 'Require Torrent',
			'type' => 'checkbox',
		],
		'f_spf' => [
			'name'  => 'Minimum Pages',
			'type'  => 'number',
		],
		'f_spt' => [
			'name'  => 'Maximum Pages',
			'type'  => 'number',
		],
		'f_srdd' => [
			'name'   => 'Minimum Rating',
			'type'   => 'list',
			'values' => [
				'Any Rating' => '0',
				'2 Stars'    => '2',
				'3 Stars'    => '3',
				'4 Stars'    => '4',
				'5 Stars'    => '5',
			],
		],
	];

	const NAME = 'E-Hentai';
	const URI  = 'https://e-hentai.org';
	const PARAMETERS = [
		'global' => [
			'thumbnails' => [
				'name'         => 'Include Thumbnails',
				'title'        => 'include thumbnail for every entry',
				'type'         => 'checkbox',
				'defaultValue' => 'checked',
			],
			'author_links' => [
				'name'         => 'Include Authors\' Links',
				'title'        => 'include link to author profile for every entry using HTML <a> tag',
				'type'         => 'checkbox',
				'defaultValue' => 'checked',
			],
			'torrents' => [
				'name'  => 'Include Torrents',
				'title' => 'include torrent links as attachments in entries that have torrents (WARNING makes additional request for every entry)',
				'type'  => 'checkbox',
			],
		],
		'search'  => self::_SHARED_PARAMETERS,
		'popular' => self::_CATEGORY_PARAMETER,
		'watched' => self::_SHARED_PARAMETERS,
		'favorites' => [
			...self::_SEARCH_PARAMETER,
			'favcat' => [
				'name'    => 'Favorite Category',
				'title'   => 'the favorite category number or "all"',
				'type'    => 'text',
				'pattern' => '\d|all',
			],
			'inline_set' => [
				'name'   => 'Order',
				'type'   => 'list',
				'values' => [
					'Published Time' => 'fs_p',
					'Favorited Time' => 'fs_f',
				],
			],
		],
		// 'bounty' => [], //TODO: Maybe add support for bounties later? It would need special parser as it's not gallery list. Dunno if anyone would use it.
	];
	const CONFIGURATION = [
		'exhentai'      => [
			'defaultValue' => false,
		],
		// cookies:
		// * selected profile
		'sp'             => [ 'defaultValue' => null, ],
		// * auth cookies
		'igneous'        => [ 'defaultValue' => null, ],
		'ipb_member_id'  => [ 'defaultValue' => null, ],
		'ipb_pass_hash'  => [ 'defaultValue' => null, ],
		'ipb_session_id' => [ 'defaultValue' => null, ],
		'sk'             => [ 'defaultValue' => null, ],
		'yay'            => [ 'defaultValue' => 'louder', ],
	];

	const CACHE_TIMEOUT = 1800;

	public array $cookies = [
		'sl' => 'dm_2',
	];
	public array $headers     = [];
	public array $queryParams = [];

	// Gather the inputs from the user
	public function collectData()
	{
		$this->cookies = array_reduce(
			$this->getOption('exhentai')
				? [ 'igneous', 'ipb_member_id', 'ipb_pass_hash', 'sk', 'yay', ]
				: [ 'ipb_member_id', 'ipb_pass_hash', 'ipb_session_id', 'sk' ],
			fn($agg, $key) => ($agg += ($val = $this->getOption($key)) !== null ? [ $key => $val ] : []),
			$this->cookies,
		);

		foreach ($this->inputs[$this->queriedContext] as $key => ['value' => $value])
			if ($key === 'categories')
				$this->queryParams['f_cats'] = array_reduce($value, fn($agg, $val) => $agg & ~(int)$val, 1023);
			else if (array_key_exists($key, self::QUERY_PARAM_KEYS) && !is_null($value = match($key) {
				'f_search'                => $value === '' ? null : $value, // do not pass empty string
				'favcat'                  => (is_numeric($value) && $value >= 0 && $value <= 9) ? (int)$value : null, // do not pass when other than 0-9 ('all' is default)
				'f_sfl', 'f_sfu', 'f_sft' => !$value ?: null, // flipped logic - flip and pass if falsy
				default                   => $value ?: null,  // normal logic  - do not pass if falsy (eg. false, 0, '', '0')
			})) $this->queryParams[$key] = $value;
		$this->_scrape();
	}

	private function _scrape()
	{
		$headers = [
			...$this->headers,
			'Cookie: ' . implode('; ', array_map(fn($k, $v) => $k.'='.$v, array_keys($this->cookies), array_values($this->cookies))),
		];

		$getTorrentURLs = fn($url) => array_map(fn($a) => $a->href, getSimpleHTMLDOM($url, $headers)->find('form table a'));

		$dom = getSimpleHTMLDOM($this->getURI(), $headers);

		$this->items = array_map(fn($el) => [
			'title' => $el->find('.glink', 0)->innertext,
			'uri' => ($image = $el->find('td.gl1e > div > a', 0))->href,
			// author might be null if gallery is disowned
			'author' => ($meta = $el->find('td.gl2e > div > div.gl3e', 0))->find('.ir + div > a', 0)?->__get($this->getInput('author_links') ? 'outertext' : 'innertext'),
			'timestamp' => strtotime($meta->find('div[id^="posted"]', 0)->innertext),
			'content' =>
				$this->getInput('thumbnails')
					// replace the host so cookies are not required on the client-side
					? '<img src="'. str_replace('s.exhentai.org', 'ehgt.org', $image->first_child()->src) . '" alt="thumbnail" referrerpolicy="no-referrer">'
					: null,
			'enclosures' =>
				($this->getInput('torrents') && $torrent_page_url = $meta->find('.gldown > a', 0)?->href)
					// decode special characters that might be present in the URL
					? $getTorrentURLs(htmlspecialchars_decode($torrent_page_url, ENT_QUOTES | ENT_SUBSTITUTE))
					: null,
			'categories' => [
				// category as tag
				'category:' . strtolower($meta->find('div.cn', 0)->innertext),
				// other tags
				...array_map(fn($tag) => $tag->title, $el->find('[class^="gt"]')),
			],
			// the path from the URL without /g/ and leading slash
			'uid' => substr(parse_url($image->href, PHP_URL_PATH), 4, -1),
		], array_filter(
			$dom->find('table[class^="itg gl"] > tr'),
			// filter ads
			fn($el) => !$el->first_child()->hasClass('itd')
		));
	}

	public function getURI()
	{
		return 'https://' . strtolower($this->getSiteName()) . '.org' . match ($this->queriedContext) {
			'popular'   => '/popular',
			'watched'   => '/watched',
			'favorites' => '/favorites.php',
			default     => '/',
		} . ($this->queryParams ? ('?' . http_build_query($this->queryParams)) : '');
	}

	private function getSiteName() {
		return 'E' . ($this->getOption('exhentai') ? 'x' : '-') . 'Hentai';
	}

	public function getName()
	{
		return match ($this->queriedContext) {
			'popular'   => 'Currently Popular Recent Galleries — ',
			'watched'   => 'Watched Tag Galleries — ',
			'favorites' => 'Favorites — ',
			'search'    => 'Recent Galleries — ',
			default     => ''
		} . $this->getSiteName();
	}

	public function detectParameters($url)
	{
		$urlRegex = '~^(?:https?://)?(?:(?:www\.)?e[-x]hentai\.org)(?<path>/(?<context>popular|watched|favorites|)(?:.php)?)(?:\?(?<query>[^#]*))?.*$~';
		$contexts = $this->getParameters();

		if (preg_match($urlRegex, $url, $urlMatches) <= 0) return null;

		$context = match ($urlMatches['context']) {
			'' => 'search',
			default => $urlMatches['path'],
		};

		$urlMatches['query'] && parse_str($urlMatches['query'], $query);

		$params = [];
		$params['context'] = $context;
		if (isset($query['f_cats'])) $params['categories'] = array_reduce(
			array_keys(self::_CATEGORY_PARAMETER['categories']['values']),
			function($agg, $key) use($query) {
				if (!(self::_CATEGORY_PARAMETER['categories']['values'][$key] & (int)$query['f_cats']))
					$agg[] = $key;
				return $agg;
			},
			[],
		);

		foreach (array_keys(self::QUERY_PARAM_KEYS) as $key)
			if(isset($query[$key])) $params[$key] = $query[$key];

		foreach ([ 'f_sfl', 'f_sfu', 'f_sft' ] as $key)
			if(isset($query[$key])) unset($params[$key]);
			else $params[$key] = 'on';


		$globalParams = array_filter($this->getParameters()['global'], fn($val) => isset($val['defaultValue']));
		foreach ($globalParams as $key => &$val)
			$val = $val['defaultValue'] === 'checked' ? 'on' : $val['defaultValue'];
		$params += $globalParams;

		return $params;
	}
}
